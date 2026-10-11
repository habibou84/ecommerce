<?php

namespace App\Services\Shipping;

use App\Exceptions\BusinessRuleException;

/**
 * Le transporteur a refusé la demande ou ne répond pas. Le message est montré au marchand.
 */
class CarrierException extends BusinessRuleException
{
    public static function unreachable(string $name): self
    {
        return new self("{$name} ne répond pas. Réessayez dans quelques instants.");
    }
}
