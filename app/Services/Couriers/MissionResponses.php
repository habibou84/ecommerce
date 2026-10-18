<?php

namespace App\Services\Couriers;

use App\Enums\Permission;
use App\Enums\ShipmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\CourierAssignment;
use App\Models\Shipment;
use App\Notifications\MissionResponse;
use App\Services\Accounts\TeamNotifier;
use App\Services\Shipping\DeliveryAdvisor;
use App\Services\Shipping\ShipmentDesk;
use Illuminate\Support\Collection;

/**
 * Réponse du livreur à une mission : vue, acceptée ou refusée avec un motif. Refusée, elle est annulée et, en
 * affectation automatique, repart aussitôt vers un autre livreur ; l'équipe est prévenue dans tous les cas.
 * Sans réponse au bout d'un quart d'heure, l'équipe reçoit un rappel.
 */
class MissionResponses
{
    public const REMIND_AFTER_MINUTES = 15;

    public function __construct(
        private readonly ShipmentDesk $shipments,
        private readonly DeliveryAdvisor $advisor,
        private readonly TeamNotifier $team,
    ) {}

    /**
     * Affectation en cours de la mission (celle du livreur actuel).
     */
    public function current(Shipment $shipment): ?CourierAssignment
    {
        return CourierAssignment::forStore($shipment->store_id)->where('shipment_id', $shipment->id)
            ->where('courier_id', $shipment->courier_id)->where('status', 'assigned')->latest('id')->first();
    }

    /**
     * Missions affichées au livreur : notées « vues » la première fois.
     *
     * @param  Collection<int, Shipment>  $missions
     */
    public function markSeen(Collection $missions): void
    {
        if ($missions->isEmpty()) {
            return;
        }
        CourierAssignment::withoutGlobalScopes()->whereIn('shipment_id', $missions->pluck('id'))->where('status', 'assigned')
            ->whereNull('seen_at')->update(['seen_at' => now()]);
    }

    public function accept(Shipment $shipment, Courier $courier): CourierAssignment
    {
        $assignment = $this->ownAssignment($shipment, $courier);
        if ($assignment->accepted_at === null) {
            $assignment->forceFill(['accepted_at' => now(), 'seen_at' => $assignment->seen_at ?? now()])->save();
        }

        return $assignment;
    }

    /**
     * Mission refusée avant le ramassage : annulée (la commande revient à « Confirmée »), puis confiée à un autre
     * livreur si l'affectation est automatique.
     */
    public function decline(Shipment $shipment, Courier $courier, string $reason): ?Shipment
    {
        $assignment = $this->ownAssignment($shipment, $courier);
        if ($shipment->status !== ShipmentStatus::AwaitingPickup) {
            throw new BusinessRuleException('Le colis est déjà récupéré : la mission ne peut plus être refusée.');
        }

        $assignment->forceFill(['status' => 'declined', 'declined_at' => now(), 'completed_at' => now(), 'decline_reason' => $reason, 'seen_at' => $assignment->seen_at ?? now()])->save();
        $this->shipments->cancel($shipment, "Refusée par {$courier->name} : {$reason}", $courier->user);

        $next = $this->advisor->autoAssign($shipment->order->fresh());
        $this->team->notify($shipment->store, Permission::ShipmentsManage->value, new MissionResponse($shipment, 'Mission refusée par '.$courier->name,
            $reason.' · '.($next ? 'confiée à '.($next->courier?->name ?? $next->connection?->name) : 'à confier à un autre livreur')), $courier->user);

        return $next;
    }

    /**
     * Missions toujours sans réponse après un quart d'heure : l'équipe est prévenue, le livreur relancé (une fois).
     */
    public function remindUnanswered(): int
    {
        $late = CourierAssignment::withoutGlobalScopes()->with(['shipment.order', 'shipment.store', 'courier.user'])
            ->where('status', 'assigned')->whereNull('accepted_at')->whereNull('reminded_at')
            ->where('assigned_at', '<', now()->subMinutes(self::REMIND_AFTER_MINUTES))
            ->whereHas('shipment', fn ($q) => $q->withoutGlobalScopes()->where('status', ShipmentStatus::AwaitingPickup->value))->get();

        foreach ($late as $assignment) {
            $shipment = $assignment->shipment;
            $assignment->forceFill(['reminded_at' => now()])->save();
            $this->team->notify($shipment->store, Permission::ShipmentsManage->value, new MissionResponse($shipment, 'Mission pas encore acceptée',
                $assignment->courier->name.($assignment->seen_at ? ' l\'a vue sans répondre' : ' ne l\'a pas encore vue').' · confiez-la à un autre livreur si besoin'));
            $assignment->courier->user?->notify(new MissionResponse($shipment, 'Mission en attente de votre réponse', 'Acceptez-la ou refusez-la', '/livreur/missions/'.$shipment->id));
        }

        return $late->count();
    }

    private function ownAssignment(Shipment $shipment, Courier $courier): CourierAssignment
    {
        if ($shipment->courier_id !== $courier->id) {
            throw new BusinessRuleException('Cette mission ne vous est pas confiée.');
        }

        return $this->current($shipment) ?? throw new BusinessRuleException('Cette mission ne vous est plus confiée.');
    }
}
