<?php

namespace App\Enums;

enum ConfirmationOutcome: string
{
    case Confirmed = 'confirmed';
    case NoAnswer = 'no_answer';
    case Callback = 'callback';
    case Changed = 'changed';
    case WrongNumber = 'wrong_number';
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmée',
            self::NoAnswer => 'Injoignable',
            self::Callback => 'À rappeler',
            self::Changed => 'Modification demandée',
            self::WrongNumber => 'Faux numéro',
            self::Refused => 'Refusée par le client',
        };
    }

    /**
     * La commande est annulée (et compte comme un refus pour le client).
     */
    public function cancels(): bool
    {
        return in_array($this, [self::WrongNumber, self::Refused], true);
    }
}
