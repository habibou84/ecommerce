<?php

namespace App\Enums;

enum StoreStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Essai',
            self::Active => 'Active',
            self::PastDue => 'Paiement en retard',
            self::Suspended => 'Suspendue',
            self::Closed => 'Fermée',
        };
    }

    /**
     * La boutique et sa gestion sont accessibles.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Trial, self::Active, self::PastDue], true);
    }
}
