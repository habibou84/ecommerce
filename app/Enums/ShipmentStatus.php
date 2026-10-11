<?php

namespace App\Enums;

/**
 * Statut normalisé d'une expédition, quel que soit le transporteur (correspondance : docs/PLAN.md §5.4).
 */
enum ShipmentStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case AwaitingPickup = 'awaiting_pickup';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Rescheduled = 'rescheduled';
    case Returning = 'returning';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Demandée',
            self::Accepted => 'Acceptée',
            self::Rejected => 'Refusée par le transporteur',
            self::AwaitingPickup => 'En attente de ramassage',
            self::InTransit => 'En transit',
            self::OutForDelivery => 'En cours de livraison',
            self::Delivered => 'Livrée',
            self::Failed => 'Échec de livraison',
            self::Rescheduled => 'Reportée',
            self::Returning => 'En retour',
            self::Returned => 'Retournée',
            self::Cancelled => 'Annulée',
            self::Lost => 'Perdue',
        };
    }

    /**
     * Statut de Delivery App → statut normalisé.
     */
    public static function fromDeliveryApp(string $status): ?self
    {
        return match ($status) {
            'pending' => self::Requested,
            'confirmed' => self::Accepted,
            'rejected' => self::Rejected,
            'pickup_assigned', 'pickup_in_progress' => self::AwaitingPickup,
            'picked_up', 'at_hub', 'delivery_assigned' => self::InTransit,
            'out_for_delivery' => self::OutForDelivery,
            'delivered' => self::Delivered,
            'delivery_failed' => self::Failed,
            'rescheduled' => self::Rescheduled,
            'return_assigned', 'returning' => self::Returning,
            'returned' => self::Returned,
            'cancelled' => self::Cancelled,
            'lost' => self::Lost,
            default => null,
        };
    }

    /**
     * Le colis a quitté la boutique (le stock sort).
     */
    public function hasLeft(): bool
    {
        return in_array($this, [self::InTransit, self::OutForDelivery, self::Delivered, self::Failed, self::Rescheduled, self::Returning, self::Returned, self::Lost], true);
    }

    /**
     * Expédition terminée : plus aucun changement attendu du transporteur.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Returned, self::Cancelled, self::Rejected, self::Lost], true);
    }

    /**
     * Encore annulable auprès du transporteur (avant le ramassage).
     */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Requested, self::Accepted, self::AwaitingPickup], true);
    }

    /**
     * Statuts qu'on peut saisir à la main (transporteur sans API).
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Accepted, self::InTransit, self::Delivered, self::Failed, self::Returning, self::Returned, self::Cancelled, self::Lost];
    }
}
