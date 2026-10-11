<?php

namespace App\Services\Payments\Gateways;

final class Checkout
{
    public function __construct(public readonly string $url, public readonly ?string $reference = null, public readonly array $raw = []) {}
}
