<?php

namespace App\Enums;

/**
 * Statut de la commande (la vente). Le paiement et l'expédition ont leurs propres statuts.
 */
enum OrderStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::PendingConfirmation => 'À confirmer',
            self::Confirmed => 'Confirmée',
            self::Processing => 'En préparation',
            self::Shipped => 'En route',
            self::Completed => 'Livrée',
            self::Cancelled => 'Annulée',
            self::Returned => 'Retournée',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingConfirmation => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Processing, self::Shipped, self::Completed, self::Cancelled],
            self::Processing => [self::Confirmed, self::Shipped, self::Completed, self::Cancelled],
            // Livrée sans passer par l'expédition : remise en main propre, retrait en boutique
            self::Shipped => [self::Completed, self::Returned],
            self::Completed => [self::Returned],
            self::Cancelled, self::Returned => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Les articles sont encore réservés en stock (pas encore partis).
     */
    public function holdsStock(): bool
    {
        return in_array($this, [self::PendingConfirmation, self::Confirmed, self::Processing], true);
    }

    /**
     * Articles, adresse et montants encore modifiables.
     */
    public function isEditable(): bool
    {
        return $this->holdsStock();
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Cancelled, self::Returned], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
