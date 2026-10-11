<?php

namespace App\Services\Payments\Gateways;

/**
 * État d'un paiement lu chez le prestataire (contrôle côté serveur : seule source qui valide un paiement).
 */
final class GatewayResult
{
    public const SUCCEEDED = 'succeeded';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?int $amount = null,
        public readonly ?string $method = null,
        public readonly ?int $fee = null,
        public readonly ?string $reason = null,
        public readonly array $raw = [],
    ) {}
}
