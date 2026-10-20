<?php

namespace App\Services\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\CourierAssignment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;

/**
 * Expédition telle que l'affichent la gestion (fiche commande, liste des expéditions).
 */
class ShipmentPresenter
{
    /**
     * Réponse du livreur à la mission en cours : vue, acceptée (ou en attente).
     *
     * @return array{seen_at: ?string, accepted_at: ?string}
     */
    private static function response(Shipment $s): array
    {
        $a = CourierAssignment::forStore($s->store_id)->where('shipment_id', $s->id)->where('courier_id', $s->courier_id)->where('status', 'assigned')->latest('id')->first();

        return ['seen_at' => $a?->seen_at?->toIso8601String(), 'accepted_at' => $a?->accepted_at?->toIso8601String()];
    }

    /**
     * @return array{edit: ?string, decide: bool, return: bool, note: bool}|null
     */
    private static function carrierActions(Shipment $s): ?array
    {
        if (! $s->connection?->isApi() || ! $s->carrier_tracking_code || $s->status->isFinal()) {
            return null;
        }

        return [
            // « all » avant le ramassage, « delivery » ensuite, rien quand le livreur est en route
            'edit' => $s->status === ShipmentStatus::OutForDelivery ? null : ($s->status->hasLeft() ? 'delivery' : 'all'),
            'decide' => (bool) $s->needs_decision,
            'return' => $s->status->hasLeft() && ! $s->return_requested && ! $s->needs_decision,
            'note' => true,
        ];
    }

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
            'courier' => $s->courier ? ['id' => $s->courier->id, 'name' => $s->courier->name, 'phone' => $s->courier->phone] + self::response($s) : null,
            'origin' => $s->origin?->name,
            'tracking_code' => $s->carrier_tracking_code,
            'tracking_url' => $s->carrier_tracking_url,
            'carrier_status' => $s->carrier_status,
            'cod_amount' => $s->cod_amount,
            'collected_amount' => $s->collected_amount,
            'carrier_fee' => $s->carrier_fee,
            'fee_payer' => $s->fee_payer,
            'delivery_code' => $s->delivery_code,
            // Toujours par la boutique : le lien du transporteur expire, il est redemandé au besoin
            'proof_photo_url' => $s->proof_photo_path || $s->proof_photo_url ? '/api/v1/shipments/'.$s->id.'/proof' : null,
            'rescheduled_to' => $s->rescheduled_to?->toDateString(),
            'scheduled_date' => $s->scheduled_date?->toDateString(),
            'needs_decision' => $s->needs_decision,
            'return_requested' => $s->return_requested,
            // Actions possibles sur la course chez l'entreprise de livraison (Delivery App)
            'carrier_actions' => self::carrierActions($s),
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
