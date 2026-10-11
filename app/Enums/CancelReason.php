<?php

namespace App\Enums;

enum CancelReason: string
{
    case CustomerRefused = 'customer_refused';
    case Unreachable = 'unreachable';
    case WrongNumber = 'wrong_number';
    case OutOfStock = 'out_of_stock';
    case Duplicate = 'duplicate';
    case Fraud = 'fraud';
    case CustomerRequest = 'customer_request';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomerRefused => 'Refus du client',
            self::Unreachable => 'Client injoignable',
            self::WrongNumber => 'Faux numéro',
            self::OutOfStock => 'Rupture de stock',
            self::Duplicate => 'Doublon',
            self::Fraud => 'Commande suspecte',
            self::CustomerRequest => 'Annulée à la demande du client',
            self::Other => 'Autre',
        };
    }

    /**
     * Motif imputable au client : compte dans son taux de refus.
     */
    public function isCustomerFault(): bool
    {
        return in_array($this, [self::CustomerRefused, self::Unreachable, self::WrongNumber, self::Fraud], true);
    }
}
