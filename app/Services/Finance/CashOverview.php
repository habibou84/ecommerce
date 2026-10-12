<?php

namespace App\Services\Finance;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Models\CarrierPayout;
use App\Models\CashCollection;
use App\Models\CashMovement;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\CourierRemittance;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Store;
use App\Services\Shipping\CarrierBalances;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caisse de la boutique : où est l'argent en ce moment (livreurs, transporteurs, clients à crédit, à payer)
 * et ce qui est entré ou sorti sur une période, par canal, avec la part en espèces.
 *
 * Chaque franc n'est compté qu'une fois : l'encaissement d'un livreur compte quand il est remis à la boutique,
 * celui d'un transporteur quand il est reversé.
 */
class CashOverview
{
    public const PERIODS = ['today', 'week', 'month', '30d'];

    public function __construct(private readonly CarrierBalances $carriers) {}

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function range(Store $store, string $period): array
    {
        $now = CarbonImmutable::now($store->timezone);
        $from = match ($period) {
            'week' => $now->subDays(6)->startOfDay(),
            'month' => $now->startOfMonth(),
            '30d' => $now->subDays(29)->startOfDay(),
            default => $now->startOfDay(),
        };

        return ['from' => $from->utc(), 'to' => $now->utc()];
    }

    /**
     * @return array<string, mixed>
     */
    public function position(Store $store): array
    {
        $carrierLines = $this->carriers->pending($store);
        $credit = Order::forStore($store->id)->where('status', OrderStatus::Completed->value)
            ->whereRaw('total - amount_paid + amount_refunded > 0');

        return [
            'couriers_cash' => (int) CashCollection::forStore($store->id)->whereNull('remittance_id')->where('method', 'cash')->sum('amount'),
            'couriers_cash_count' => (int) CashCollection::forStore($store->id)->whereNull('remittance_id')->where('method', 'cash')->distinct()->count('courier_id'),
            'carriers_due' => (int) $carrierLines->sum('net'),
            'carriers_due_overdue' => (int) $carrierLines->where('overdue', true)->sum('net'),
            'customers_due' => (int) (clone $credit)->sum(DB::raw('total - amount_paid + amount_refunded')),
            'customers_due_orders' => (clone $credit)->orderBy('completed_at')->limit(10)
                ->get(['id', 'store_id', 'number', 'customer_name', 'total', 'amount_paid', 'amount_refunded', 'completed_at'])
                ->map(fn (Order $o) => [
                    'id' => $o->id,
                    'reference' => $o->setRelation('store', $store)->reference(),
                    'customer' => $o->customer_name,
                    'due' => $o->amountDue(),
                    'completed_at' => $o->completed_at?->toIso8601String(),
                ])->values(),
            'customers_due_count' => (clone $credit)->count(),
            'couriers_to_pay' => (int) CourierEarning::forStore($store->id)->whereNull('payout_id')->sum('amount')
                + (int) CourierPayout::forStore($store->id)->where('status', 'draft')->sum('total'),
        ];
    }

    /**
     * Entrées et sorties de la période, par canal : montant, part en espèces, nombre d'opérations.
     *
     * @return array{lines: list<array{key: string, label: string, amount: int, cash: int, count: int}>, total: int, cash: int, online_fees: int}
     */
    public function flows(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $payments = fn (array $kinds) => Payment::forStore($store->id)->where('status', 'succeeded')
            ->whereIn('kind', array_map(fn (PaymentKind $k) => $k->value, $kinds))->whereBetween('paid_at', [$from, $to]);

        $counter = $payments([PaymentKind::Deposit, PaymentKind::Manual])->get(['amount', 'method']);
        $courierMobile = $payments([PaymentKind::CodMobile])->get(['amount', 'method']);
        $online = $payments([PaymentKind::Online])->get(['amount', 'fee']);
        $remittances = CourierRemittance::forStore($store->id)->whereBetween('received_at', [$from, $to])->get(['received_amount', 'method']);
        $carrierPayouts = CarrierPayout::forStore($store->id)->whereBetween('paid_at', [$from, $to])->get(['amount', 'method']);
        $refunds = Refund::forStore($store->id)->where('status', 'succeeded')->whereBetween('refunded_at', [$from, $to])->get(['amount']);
        $courierPay = CourierPayout::forStore($store->id)->where('status', 'paid')->whereBetween('paid_at', [$from, $to])->get(['total', 'method']);
        $movements = CashMovement::forStore($store->id)->whereBetween('occurred_at', [$from, $to])->get(['type', 'amount', 'method']);
        $expenses = $movements->where('type', 'expense');
        $drawerIn = $movements->where('type', 'deposit');
        $drawerOut = $movements->where('type', 'withdrawal');

        $cash = fn ($rows, string $column) => (int) $rows
            ->filter(fn ($r) => ($r->method instanceof BackedEnum ? $r->method->value : $r->method) === 'cash')->sum($column);

        $lines = [
            ['key' => 'counter', 'label' => 'Encaissé par la boutique', 'amount' => (int) $counter->sum('amount'), 'cash' => $cash($counter, 'amount'), 'count' => $counter->count()],
            ['key' => 'courier_remittances', 'label' => 'Remis par les livreurs', 'amount' => (int) $remittances->sum('received_amount'), 'cash' => $cash($remittances, 'received_amount'), 'count' => $remittances->count()],
            ['key' => 'courier_mobile', 'label' => 'Payé aux livreurs par mobile money', 'amount' => (int) $courierMobile->sum('amount'), 'cash' => 0, 'count' => $courierMobile->count()],
            ['key' => 'carrier_payouts', 'label' => 'Reversé par les transporteurs', 'amount' => (int) $carrierPayouts->sum('amount'), 'cash' => $cash($carrierPayouts, 'amount'), 'count' => $carrierPayouts->count()],
            ['key' => 'online', 'label' => 'Paiements en ligne (frais déduits)', 'amount' => (int) ($online->sum('amount') - $online->sum('fee')), 'cash' => 0, 'count' => $online->count()],
            ['key' => 'refunds', 'label' => 'Remboursements', 'amount' => -(int) $refunds->sum('amount'), 'cash' => 0, 'count' => $refunds->count()],
            ['key' => 'courier_pay', 'label' => 'Paie des livreurs', 'amount' => -(int) $courierPay->sum('total'), 'cash' => -$cash($courierPay, 'total'), 'count' => $courierPay->count()],
            ['key' => 'expenses', 'label' => 'Dépenses', 'amount' => -(int) $expenses->sum('amount'), 'cash' => -$cash($expenses, 'amount'), 'count' => $expenses->count()],
            // Retraits (banque, prélèvement) et apports de monnaie : l'argent change de place, la boutique ne gagne ni ne perd
            ['key' => 'drawer', 'label' => 'Retraits et apports de la caisse', 'amount' => 0,
                'cash' => $cash($drawerIn, 'amount') - $cash($drawerOut, 'amount'), 'count' => $drawerIn->count() + $drawerOut->count()],
        ];

        return [
            'lines' => $lines,
            'total' => array_sum(array_column($lines, 'amount')),
            'cash' => array_sum(array_column($lines, 'cash')),
            'online_fees' => (int) $online->sum('fee'),
        ];
    }
}
