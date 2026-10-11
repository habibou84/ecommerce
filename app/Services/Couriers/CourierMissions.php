<?php

namespace App\Services\Couriers;

use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierAssignment;
use App\Models\CourierEarning;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\CourierMission;

/**
 * Effets d'une mission sur le livreur : historique des assignations, argent encaissé (qu'il détient jusqu'au
 * versement) et gains selon sa règle de rémunération.
 */
class CourierMissions
{
    public function assign(Shipment $shipment, Courier $courier, ?User $actor): void
    {
        CourierAssignment::forStore($shipment->store_id)->where('shipment_id', $shipment->id)->where('status', 'assigned')
            ->update(['status' => 'reassigned', 'completed_at' => now()]);

        CourierAssignment::create([
            'store_id' => $shipment->store_id,
            'shipment_id' => $shipment->id,
            'courier_id' => $courier->id,
            'status' => 'assigned',
            'assigned_by' => $actor?->id,
            'assigned_at' => now(),
        ]);
        $shipment->forceFill(['courier_id' => $courier->id])->save();

        $courier->user?->notify(new CourierMission($shipment->setRelation('courier', $courier)));
    }

    public function afterStatus(Shipment $shipment, ShipmentStatus $to): void
    {
        $courier = $shipment->courier;
        if ($courier === null) {
            return;
        }
        $rule = $courier->payRule;
        $zoneId = $shipment->order->delivery_zone_id;

        match ($to) {
            ShipmentStatus::Delivered => $rule && $rule->amountFor($zoneId) > 0
                ? $this->earn($shipment, $courier, 'delivery', $rule->amountFor($zoneId)) : null,
            ShipmentStatus::Failed, ShipmentStatus::Rescheduled => $rule && $rule->failed_attempt_amount > 0
                ? $this->earn($shipment, $courier, 'failed_attempt', $rule->failed_attempt_amount) : null,
            default => null,
        };

        if (in_array($to, [ShipmentStatus::Delivered, ShipmentStatus::Returned, ShipmentStatus::Cancelled, ShipmentStatus::Lost], true)) {
            CourierAssignment::forStore($shipment->store_id)->where('shipment_id', $shipment->id)->where('status', 'assigned')
                ->update(['status' => $to === ShipmentStatus::Cancelled ? 'cancelled' : 'completed', 'completed_at' => now()]);
        }
    }

    /**
     * Montant encaissé par le livreur : en espèces il le détient (caisse) ; en mobile money il est déjà chez la boutique.
     */
    public function collected(Shipment $shipment, Payment $payment, PaymentMethod $method): void
    {
        CashCollection::create([
            'store_id' => $shipment->store_id,
            'shipment_id' => $shipment->id,
            'courier_id' => $shipment->courier_id,
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'method' => $method->value,
            'collected_at' => now(),
        ]);
    }

    private function earn(Shipment $shipment, Courier $courier, string $type, int $amount): void
    {
        CourierEarning::create([
            'store_id' => $shipment->store_id,
            'courier_id' => $courier->id,
            'shipment_id' => $shipment->id,
            'type' => $type,
            'amount' => $amount,
        ]);
    }
}
