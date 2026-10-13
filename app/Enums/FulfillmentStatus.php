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
            self::Unfulfilled => 'Non envoyée',
            self::Partial => 'Envoyée en partie',
            self::Fulfilled => 'Envoyée',
            self::Returned => 'Retournée',
        };
    }
}
