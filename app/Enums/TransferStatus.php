<?php

namespace App\Enums;

enum TransferStatus: string
{
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::InTransit => 'En route',
            self::Received => 'Reçu',
            self::Cancelled => 'Annulé',
        };
    }
}
