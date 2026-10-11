<?php

namespace App\Enums;

enum FulfillmentStatus: string
{
    case Unfulfilled = 'unfulfilled';
    case Partial = 'partial';
    case Fulfilled = 'fulfilled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Unfulfilled => 'Non expédiée',
            self::Partial => 'Expédiée en partie',
            self::Fulfilled => 'Expédiée',
            self::Returned => 'Retournée',
        };
    }
}
