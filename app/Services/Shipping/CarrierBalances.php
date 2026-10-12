<?php

namespace App\Services\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\CarrierConnection;
use App\Models\Shipment;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Ce que les transporteurs doivent à la boutique : courses livrées pas encore comprises dans un reversement,
 * chacune comptée comme le rapprochement le fera (encaissé − tarif du transporteur). Une commande déjà payée
 * (rien à encaisser) compte en négatif : c'est la boutique qui doit les frais de livraison.
 */
class CarrierBalances
{
    /**
     * @return list<array{connection_id: int, carrier: string, is_api: bool, status: string, amount: int, count: int, overdue_count: int, overdue_amount: int, oldest_delivered_at: ?string}>
     */
    public function summary(Store $store): array
    {
        $lines = $this->pending($store)->groupBy('connection_id');

        return CarrierConnection::forStore($store->id)
            ->where(fn ($q) => $q->where('status', '!=', 'disabled')->orWhereIn('id', $lines->keys()))
            ->orderBy('name')->get()
            ->map(function (CarrierConnection $connection) use ($lines) {
                $mine = $lines->get($connection->id, collect());
                $overdue = $mine->where('overdue', true);

                return [
                    'connection_id' => $connection->id,
                    'carrier' => $connection->name,
                    'is_api' => $connection->isApi(),
                    'status' => $connection->status,
                    'amount' => (int) $mine->sum('net'),
                    'count' => $mine->count(),
                    'overdue_count' => $overdue->count(),
                    'overdue_amount' => (int) $overdue->sum('net'),
                    'oldest_delivered_at' => $mine->min('delivered_at'),
                ];
            })->values()->all();
    }

    /**
     * Courses livrées non reversées, la plus ancienne d'abord.
     *
     * @return Collection<int, array{shipment_id: int, connection_id: int, order_id: int, order_reference: string, tracking_code: ?string, delivered_at: ?string, days: int, overdue: bool, collected: int, fees: int, net: int}>
     */
    public function pending(Store $store, ?CarrierConnection $connection = null): Collection
    {
        $overdueDays = $this->overdueDays();

        return Shipment::forStore($store->id)
            // Boutique explicite : aussi juste hors requête (rapport du jour), quelle que soit la boutique courante
            ->with(['order' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'store_id', 'number'), 'order.store:id,order_prefix'])
            ->whereNotNull('carrier_connection_id')
            ->when($connection, fn ($q) => $q->where('carrier_connection_id', $connection->id))
            ->where('status', ShipmentStatus::Delivered->value)
            ->whereDoesntHave('payoutLines')
            ->orderBy('delivered_at')->orderBy('id')
            ->get()
            ->map(function (Shipment $s) use ($overdueDays) {
                $collected = (int) ($s->collected_amount ?? $s->cod_amount);
                $fees = (int) $s->carrier_fee;
                $days = $s->delivered_at ? (int) $s->delivered_at->diffInDays(now()) : 0;

                return [
                    'shipment_id' => $s->id,
                    'connection_id' => (int) $s->carrier_connection_id,
                    'order_id' => $s->order_id,
                    'order_reference' => $s->reference(),
                    'tracking_code' => $s->carrier_tracking_code,
                    'delivered_at' => $s->delivered_at?->toIso8601String(),
                    'days' => $days,
                    'overdue' => $days >= $overdueDays,
                    'collected' => $collected,
                    'fees' => $fees,
                    'net' => $collected - $fees,
                ];
            });
    }

    /**
     * Relevé CSV (séparateur « ; », lisible par Excel) des courses en attente de reversement.
     */
    public function csv(Store $store, CarrierConnection $connection): string
    {
        $rows = $this->pending($store, $connection);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Commande', 'Course', 'Livrée le', 'Jours', 'Encaissé', 'Frais', 'Net à recevoir'], ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['order_reference'], $r['tracking_code'] ?? '',
                $r['delivered_at'] ? now()->parse($r['delivered_at'])->setTimezone($store->timezone)->format('d/m/Y H:i') : '',
                $r['days'], $r['collected'], $r['fees'], $r['net'],
            ], ';', '"', '');
        }
        fputcsv($out, ['Total', '', '', '', $rows->sum('collected'), $rows->sum('fees'), $rows->sum('net')], ';', '"', '');
        rewind($out);

        return (string) stream_get_contents($out);
    }

    public function overdueDays(): int
    {
        return max(1, (int) config('platform.carrier_payout_overdue_days', 7));
    }
}
