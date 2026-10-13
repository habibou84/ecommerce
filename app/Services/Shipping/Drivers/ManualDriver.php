<?php

namespace App\Services\Shipping\Drivers;

use App\Enums\ShipmentStatus;
use App\Models\CarrierConnection;
use App\Models\Shipment;
use App\Services\Shipping\CarrierException;

/**
 * Transporteur sans API : la course est demandée par WhatsApp (message préparé) et son statut saisi à la main.
 */
class ManualDriver implements CarrierDriver
{
    public function account(CarrierConnection $connection): array
    {
        return ['name' => $connection->name];
    }

    public function zones(CarrierConnection $connection): array
    {
        return [];
    }

    public function hubs(CarrierConnection $connection): array
    {
        return [];
    }

    public function products(CarrierConnection $connection): array
    {
        return [];
    }

    public function quote(CarrierConnection $connection, array $draft): ?int
    {
        return null;
    }

    public function create(CarrierConnection $connection, Shipment $shipment, array $payload): RemoteShipment
    {
        return new RemoteShipment(trackingCode: '', status: ShipmentStatus::Requested, rawStatus: 'requested');
    }

    public function cancel(CarrierConnection $connection, Shipment $shipment, ?string $reason): RemoteShipment
    {
        return new RemoteShipment(trackingCode: (string) $shipment->carrier_tracking_code, status: ShipmentStatus::Cancelled, rawStatus: 'cancelled');
    }

    public function fetch(CarrierConnection $connection, Shipment $shipment): RemoteShipment
    {
        throw new CarrierException('Cette entreprise de livraison n\'a pas d\'API : mettez le statut à jour à la main.');
    }

    public function changedSince(CarrierConnection $connection, \DateTimeInterface $since): array
    {
        return [];
    }

    public function registerWebhook(CarrierConnection $connection, string $url, array $events): array
    {
        throw new CarrierException('Cette entreprise de livraison n\'a pas d\'API.');
    }

    public function removeWebhook(CarrierConnection $connection): void {}

    public function payout(CarrierConnection $connection, string $reference): array
    {
        throw new CarrierException('Cette entreprise de livraison n\'a pas d\'API.');
    }

    public function paidPayouts(CarrierConnection $connection): array
    {
        return [];
    }
}
