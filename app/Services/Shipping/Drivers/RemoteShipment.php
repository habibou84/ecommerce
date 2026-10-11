<?php

namespace App\Services\Shipping\Drivers;

use App\Enums\ShipmentStatus;

/**
 * Course telle que la décrit le transporteur, ramenée à la forme commune.
 */
final class RemoteShipment
{
    public function __construct(
        public readonly string $trackingCode,
        public readonly ?ShipmentStatus $status,
        public readonly string $rawStatus,
        public readonly ?string $trackingUrl = null,
        public readonly ?int $fee = null,
        public readonly ?int $collected = null,
        public readonly ?string $deliveryCode = null,
        public readonly ?string $incident = null,
        public readonly int $attempts = 0,
        public readonly ?string $proofPhotoUrl = null,
        public readonly ?string $merchantReference = null,
        public readonly ?string $occurredAt = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $proofPhotoPath = null,
        public readonly ?string $rescheduledTo = null,
    ) {}

    /**
     * Course de l'API publique de Delivery App (PublicOrderResource).
     *
     * @param  array<string, mixed>  $order
     */
    public static function fromDeliveryApp(array $order, ?string $occurredAt = null): self
    {
        $photos = collect($order['proof']['photos'] ?? []);
        $photo = $photos->firstWhere('type', 'photo_delivery') ?? $photos->first();

        return new self(
            trackingCode: (string) $order['tracking_code'],
            status: ShipmentStatus::fromDeliveryApp((string) ($order['status'] ?? '')),
            rawStatus: (string) ($order['status'] ?? ''),
            trackingUrl: $order['tracking_url'] ?? null,
            fee: isset($order['amounts']['delivery_fee']) ? (int) $order['amounts']['delivery_fee'] : null,
            collected: isset($order['amounts']['collected_amount']) ? (int) $order['amounts']['collected_amount'] : null,
            deliveryCode: $order['delivery_code'] ?? null,
            incident: $order['last_incident'] ?? null,
            attempts: (int) ($order['attempts_count'] ?? 0),
            proofPhotoUrl: $photo['url'] ?? null,
            merchantReference: $order['merchant_reference'] ?? null,
            // Heure du changement chez le transporteur (un webhook relancé garde la sienne)
            occurredAt: $order['updated_at'] ?? $occurredAt,
        );
    }
}
