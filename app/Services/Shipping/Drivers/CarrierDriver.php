<?php

namespace App\Services\Shipping\Drivers;

use App\Models\CarrierConnection;
use App\Models\Shipment;

/**
 * Connecteur d'une entreprise de livraison (docs/PLAN.md §5.8). Les courses du transporteur sont renvoyées
 * sous une forme commune : voir RemoteShipment.
 */
interface CarrierDriver
{
    /**
     * Vérifie l'accès et renvoie l'identité du compte chez le transporteur.
     *
     * @return array<string, mixed>
     */
    public function account(CarrierConnection $connection): array;

    /**
     * @return list<array{remote_id: string, name: string, parent_name: ?string, is_shipping: bool, is_active: bool}>
     */
    public function zones(CarrierConnection $connection): array;

    /**
     * Entrepôts du transporteur (stock que le marchand y dépose).
     *
     * @return list<array{remote_id: string, name: string}>
     */
    public function hubs(CarrierConnection $connection): array;

    /**
     * Produits du marchand stockés chez le transporteur, avec le disponible par entrepôt.
     *
     * @return list<array{remote_id: string, sku: ?string, name: string, hubs: array<string, array{on_hand: int, available: int}>}>
     */
    public function products(CarrierConnection $connection): array;

    /**
     * Prix de la course (null : pas de devis possible).
     *
     * @param  array{zone_id: string, pickup_zone_id?: ?string, is_fragile?: bool, weight_kg?: ?float}  $draft
     */
    public function quote(CarrierConnection $connection, array $draft): ?int;

    /**
     * @param  array<string, mixed>  $payload  champs NewOrder de Delivery App
     */
    public function create(CarrierConnection $connection, Shipment $shipment, array $payload): RemoteShipment;

    public function cancel(CarrierConnection $connection, Shipment $shipment, ?string $reason): RemoteShipment;

    public function fetch(CarrierConnection $connection, Shipment $shipment): RemoteShipment;

    /**
     * Courses modifiées depuis une date (rattrapage des webhooks perdus).
     *
     * @return list<RemoteShipment>
     */
    public function changedSince(CarrierConnection $connection, \DateTimeInterface $since): array;

    /**
     * Crée l'adresse de réception des webhooks chez le transporteur.
     *
     * @param  list<string>  $events
     * @return array{id: string, secret: string}
     */
    public function registerWebhook(CarrierConnection $connection, string $url, array $events): array;

    public function removeWebhook(CarrierConnection $connection): void;

    /**
     * Détail d'un reversement : lignes par course.
     *
     * @return array{remote_id: string, amount: int, total_collected: int, total_fees: int, method: ?string, transaction_ref: ?string, paid_at: ?string, lines: list<array{tracking_code: string, collected: int, fees: int}>, raw: array}
     */
    public function payout(CarrierConnection $connection, string $reference): array;

    /**
     * Références des derniers reversements payés (rattrapage d'un webhook payout.paid perdu).
     *
     * @return list<string>
     */
    public function paidPayouts(CarrierConnection $connection): array;
}
