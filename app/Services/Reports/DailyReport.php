<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\CashCollection;
use App\Models\Order;
use App\Models\ScheduledReport;
use App\Models\Shipment;
use App\Models\Store;
use App\Notifications\DailyReportMail;
use App\Services\Shipping\CarrierBalances;
use App\Services\WhatsApp\CustomerMessenger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Jibiat\Socle\Money;
use Throwable;

/**
 * Point quotidien envoyé à l'équipe (WhatsApp de la boutique ou email) : ventes du jour et travail en attente.
 */
class DailyReport
{
    public function __construct(private readonly CustomerMessenger $messenger) {}

    /**
     * @return array<string, int|string>
     */
    public function figures(Store $store, CarbonImmutable $day): array
    {
        $start = $day->setTimezone($store->timezone)->startOfDay()->utc();
        $end = $start->addDay();
        $received = Order::forStore($store->id)->whereBetween('created_at', [$start, $end]);
        $delivered = Order::forStore($store->id)->where('status', OrderStatus::Completed->value)->whereBetween('completed_at', [$start, $end]);
        $due = app(CarrierBalances::class)->pending($store);

        return [
            'date' => $day->setTimezone($store->timezone)->locale('fr')->isoFormat('dddd D MMMM'),
            'orders' => (clone $received)->count(),
            'orders_amount' => (int) (clone $received)->where('status', '!=', OrderStatus::Cancelled->value)->sum('total'),
            'delivered' => (clone $delivered)->count(),
            'delivered_amount' => (int) (clone $delivered)->sum('total'),
            'cancelled' => (clone $received)->where('status', OrderStatus::Cancelled->value)->count(),
            'to_confirm' => Order::forStore($store->id)->where('status', OrderStatus::PendingConfirmation->value)->count(),
            'to_ship' => Order::forStore($store->id)->where('status', OrderStatus::Confirmed->value)->count(),
            'in_delivery' => Shipment::forStore($store->id)->whereIn('status', ['requested', 'accepted', 'awaiting_pickup', 'in_transit', 'out_for_delivery', 'rescheduled'])->count(),
            'courier_cash' => (int) CashCollection::forStore($store->id)->whereNull('remittance_id')->where('method', 'cash')->sum('amount'),
            'carrier_due' => (int) $due->sum('net'),
            'carrier_due_overdue' => (int) $due->where('overdue', true)->sum('net'),
        ];
    }

    /**
     * @param  array<string, int|string>  $f
     */
    public function text(Store $store, array $f): string
    {
        return implode("\n", array_filter([
            "📊 {$store->name} — {$f['date']}",
            "Commandes reçues : {$f['orders']} (".Money::format($f['orders_amount']).')',
            "Livrées : {$f['delivered']} (".Money::format($f['delivered_amount']).')',
            $f['cancelled'] ? "Annulées : {$f['cancelled']}" : null,
            "À confirmer : {$f['to_confirm']} · À faire livrer : {$f['to_ship']} · En livraison : {$f['in_delivery']}",
            $f['courier_cash'] ? 'Espèces chez les livreurs : '.Money::format($f['courier_cash']) : null,
            $f['carrier_due'] ? 'À recevoir des entreprises de livraison : '.Money::format($f['carrier_due'])
                .($f['carrier_due_overdue'] ? ' (dont '.Money::format($f['carrier_due_overdue']).' en retard)' : '') : null,
        ]));
    }

    /**
     * Envoi d'un rapport ; renvoie faux si le canal n'est pas utilisable (WhatsApp non connecté, pas d'email).
     */
    public function send(ScheduledReport $report, ?CarbonImmutable $day = null): bool
    {
        $report->loadMissing('store', 'user');
        $store = $report->store;
        $figures = $this->figures($store, $day ?? CarbonImmutable::now());

        try {
            $sent = match ($report->channel) {
                'whatsapp' => $this->messenger->forTeam($store, (string) $report->user->phone, 'daily_report', [
                    $store->name, (string) $figures['date'], (string) $figures['orders'], Money::format($figures['delivered_amount']),
                    (string) $figures['to_confirm'], (string) $figures['to_ship'],
                ], 'report', $report->id) !== null,
                'email' => filled($report->user->email) ? $this->mail($report, $this->text($store, $figures)) : false,
                default => false,
            };
        } catch (Throwable $e) {
            Log::warning('Rapport quotidien non envoyé', ['report' => $report->id, 'error' => $e->getMessage()]);
            $sent = false;
        }

        $report->forceFill(['last_sent_at' => now()])->save();

        return $sent;
    }

    /**
     * Rapports dont l'heure d'envoi (heure de la boutique) est passée et qui n'ont pas encore été envoyés aujourd'hui.
     */
    public function sendDue(): int
    {
        $count = 0;
        ScheduledReport::withoutGlobalScopes()->where('is_active', true)->with('store', 'user')->get()
            ->filter(function (ScheduledReport $r) {
                if ($r->store === null || $r->user === null || ! $r->store->isOpen()) {
                    return false;
                }
                $local = now($r->store->timezone);
                $due = $local->copy()->setTimeFromTimeString($r->send_at);

                return $local->gte($due) && ($r->last_sent_at === null || $r->last_sent_at->lt($due));
            })
            ->each(function (ScheduledReport $r) use (&$count) {
                $count += $this->send($r) ? 1 : 0;
            });

        return $count;
    }

    private function mail(ScheduledReport $report, string $text): bool
    {
        $report->user->notify(new DailyReportMail($report->store->name, $text));

        return true;
    }
}
