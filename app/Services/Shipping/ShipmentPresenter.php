<?php

namespace App\Services\Shipping;

use App\Models\Shipment;
use App\Models\ShipmentEvent;

/**
 * Expédition telle que l'affichent la gestion (fiche commande, liste des expéditions).
 */
class ShipmentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Shipment $s, bool $withEvents = true): array
    {
        return [
            'id' => $s->id,
            'reference' => $s->relationLoaded('order') ? $s->reference() : null,
            'order_id' => $s->order_id,
            'mode' => $s->mode,
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'is_final' => $s->status->isFinal(),
            'is_cancellable' => $s->status->isCancellable(),
            'carrier' => $s->connection ? ['id' => $s->connection->id, 'name' => $s->connection->name, 'driver' => $s->connection->driver, 'contact_phone' => $s->connection->contact_phone]
                : ($s->courier ? ['id' => null, 'name' => $s->courier->name, 'driver' => 'own_courier', 'contact_phone' => $s->courier->phone] : null),
            'courier' => $s->courier ? ['id' => $s->courier->id, 'name' => $s->courier->name, 'phone' => $s->courier->phone] : null,
            'origin' => $s->origin?->name,
            'tracking_code' => $s->carrier_tracking_code,
            'tracking_url' => $s->carrier_tracking_url,
            'carrier_status' => $s->carrier_status,
            'cod_amount' => $s->cod_amount,
            'collected_amount' => $s->collected_amount,
            'carrier_fee' => $s->carrier_fee,
            'fee_payer' => $s->fee_payer,
            'delivery_code' => $s->delivery_code,
            'proof_photo_url' => $s->proof_photo_path ? '/api/v1/shipments/'.$s->id.'/proof' : $s->proof_photo_url,
            'rescheduled_to' => $s->rescheduled_to?->toDateString(),
            'attempts_count' => $s->attempts_count,
            'failure_reason' => $s->failure_reason,
            'requested_at' => $s->requested_at?->toIso8601String(),
            'picked_up_at' => $s->picked_up_at?->toIso8601String(),
            'delivered_at' => $s->delivered_at?->toIso8601String(),
            'last_synced_at' => $s->last_synced_at?->toIso8601String(),
            'events' => $withEvents ? $s->events->map(fn (ShipmentEvent $e) => [
                'status' => $e->status?->value,
                'status_label' => $e->status?->label(),
                'source' => $e->source,
                'note' => $e->note,
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ]) : [],
        ];
    }
}
