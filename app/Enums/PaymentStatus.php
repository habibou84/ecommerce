<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Non payée',
            self::PartiallyPaid => 'Acompte',
            self::Paid => 'Payée',
            self::PartiallyRefunded => 'Remboursée en partie',
            self::Refunded => 'Remboursée',
        };
    }
}
