<?php

namespace App\Services\Shipping;

use App\Exceptions\BusinessRuleException;
use App\Models\CarrierConnection;
use App\Models\CarrierZone;
use App\Models\CarrierZoneMapping;
use App\Models\Place;
use App\Models\Store;
use App\Services\Billing\PlanLimits;
use App\Services\Catalog\DeliveryAppImporter;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use App\Services\Shipping\Drivers\CarrierDriver;
use App\Services\Shipping\Drivers\DeliveryAppDriver;
use App\Services\Shipping\Drivers\ManualDriver;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\PhoneNumber;

/**
 * Entreprises de livraison de la boutique : connexion par clé API (Delivery App), zones et correspondance avec
 * les lieux de la boutique, adresse webhook créée automatiquement.
 */
class CarrierConnections
{
    public const WEBHOOK_EVENTS = ['order.status_changed', 'order.incident', 'order.updated', 'payout.paid'];

    public function __construct(
        private readonly DeliveryAppImporter $addresses,
        private readonly Places $places,
    ) {}

    public function driver(CarrierConnection $connection): CarrierDriver
    {
        return $connection->isApi() ? app(DeliveryAppDriver::class) : app(ManualDriver::class);
    }

    /**
     * Connexion à une entreprise qui utilise Delivery App : la clé est vérifiée (GET /me), les zones copiées et
     * rapprochées des lieux de la boutique, puis l'adresse webhook créée chez le transporteur.
     */
    public function connectDeliveryApp(Store $store, string $address, string $apiKey): CarrierConnection
    {
        $apiKey = trim($apiKey);
        if (! preg_match('/^lv_[a-z0-9]{8}_[A-Za-z0-9]{40}$/', $apiKey)) {
            throw new BusinessRuleException('Clé API invalide : elle commence par « lv_ » (Profil › Intégrations de votre entreprise de livraison).', 'api_key');
        }
        $baseUrl = $this->addresses->apiUrl($address);
        app(PlanLimits::class)->ensureCanAdd($store, 'carrier_connections');

        $connection = new CarrierConnection([
            'store_id' => $store->id,
            'driver' => CarrierConnection::DELIVERY_APP,
            'name' => parse_url($baseUrl, PHP_URL_HOST),
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'api_key_prefix' => substr($apiKey, 0, 11),
        ]);

        $account = $this->driver($connection)->account($connection);
        $scopes = $account['key']['scopes'] ?? [];
        $missing = array_diff(['orders:read', 'orders:write'], $scopes);
        if ($missing !== []) {
            throw new BusinessRuleException('Cette clé n\'a pas les droits nécessaires ('.implode(', ', $missing).') : créez-en une avec toutes les portées.', 'api_key');
        }

        $existing = CarrierConnection::forStore($store->id)->where('base_url', $baseUrl)->where('status', '!=', 'disabled')->first();
        if ($existing && ($existing->remote_account['merchant']['id'] ?? null) === ($account['merchant']['id'] ?? null)) {
            throw new BusinessRuleException('Cette entreprise de livraison est déjà connectée.', 'address');
        }

        $connection->fill([
            'name' => $account['company']['name'] ?? $connection->name,
            'scopes' => $scopes,
            'remote_account' => $account,
            'settings' => ['fee_payer' => 'store'],
        ])->save();

        $this->syncZones($connection);
        $connection->forceFill(['status' => 'active', 'connected_at' => now(), 'last_error' => null, 'last_synced_at' => now()])->save();

        // Sans webhook, la connexion fonctionne quand même : les statuts sont relus toutes les 10 minutes
        try {
            $this->registerWebhook($connection);
        } catch (CarrierException $e) {
            $connection->forceFill(['last_error' => mb_substr('Webhook non créé : '.$e->getMessage(), 0, 250)])->save();
        }

        return $connection;
    }

    /**
     * Transporteur sans API : la course lui est demandée par WhatsApp.
     */
    public function connectManual(Store $store, string $name, ?string $phone): CarrierConnection
    {
        app(PlanLimits::class)->ensureCanAdd($store, 'carrier_connections');

        return CarrierConnection::create([
            'store_id' => $store->id,
            'driver' => CarrierConnection::MANUAL,
            'name' => $name,
            'contact_phone' => PhoneNumber::normalize($phone),
            'settings' => ['fee_payer' => 'store'],
            'status' => 'active',
            'connected_at' => now(),
        ]);
    }

    public function webhookUrl(CarrierConnection $connection): string
    {
        return Tenancy::apiUrl('/webhooks/carriers/'.$connection->webhook_uuid);
    }

    /**
     * Adresse de réception créée par l'API du transporteur (portée webhooks:write). Sans cette portée, le
     * marchand la déclare lui-même et colle le secret ; la synchronisation périodique prend le relais en attendant.
     */
    public function registerWebhook(CarrierConnection $connection): void
    {
        if (! $connection->allows('webhooks:write')) {
            return;
        }

        $driver = $this->driver($connection);
        $driver->removeWebhook($connection);
        $webhook = $driver->registerWebhook($connection, $this->webhookUrl($connection), self::WEBHOOK_EVENTS);
        $connection->forceFill([
            'remote_webhook_id' => $webhook['id'],
            'webhook_secret' => $webhook['secret'],
            'settings' => [...($connection->settings ?? []), 'webhook_events' => self::WEBHOOK_EVENTS],
        ])->save();
    }

    /**
     * Adresse créée avant l'ajout d'un événement (order.updated…) : recréée avec la liste à jour, une seule fois.
     * Un échec n'empêche pas la synchronisation : nouvel essai la fois suivante.
     */
    public function refreshWebhook(CarrierConnection $connection): void
    {
        if ($connection->remote_webhook_id === null || $connection->setting('webhook_events') === self::WEBHOOK_EVENTS) {
            return;
        }

        try {
            $this->registerWebhook($connection);
        } catch (CarrierException) {
            // Transporteur injoignable : l'ancienne adresse reste en place
        }
    }

    /**
     * Copie des zones du transporteur et rapprochement automatique avec les lieux de la boutique.
     */
    public function syncZones(CarrierConnection $connection): void
    {
        $remote = $this->driver($connection)->zones($connection);

        DB::transaction(function () use ($connection, $remote) {
            $seen = [];
            foreach ($remote as $zone) {
                $seen[] = $zone['remote_id'];
                CarrierZone::updateOrCreate(
                    ['connection_id' => $connection->id, 'remote_id' => $zone['remote_id']],
                    [...$zone, 'synced_at' => now()],
                );
            }
            CarrierZone::where('connection_id', $connection->id)->whereNotIn('remote_id', $seen)->update(['is_active' => false]);
        });

        $this->autoMap($connection);
    }

    /**
     * Lieu → zone de même nom chez le transporteur (« Angré » → « Angré », sinon sa commune « Cocody »).
     * Les correspondances corrigées à la main sont gardées.
     */
    public function autoMap(CarrierConnection $connection): void
    {
        $zones = CarrierZone::where('connection_id', $connection->id)->where('is_active', true)->get()
            ->groupBy(fn (CarrierZone $z) => PlaceMatcher::normalize($z->name));
        $manual = CarrierZoneMapping::where('connection_id', $connection->id)->where('matched_by', 'manual')->pluck('place_id')->flip();

        $places = $this->places->forStore($connection->store);
        foreach ($places as $place) {
            if ($manual->has($place->id)) {
                continue;
            }
            $zone = $this->zoneFor($place, $zones);
            CarrierZoneMapping::updateOrCreate(
                ['connection_id' => $connection->id, 'place_id' => $place->id],
                ['carrier_zone_id' => $zone?->id, 'matched_by' => 'auto'],
            );
        }
    }

    /**
     * @param  Collection<string, Collection<int, CarrierZone>>  $zones
     */
    private function zoneFor(Place $place, $zones): ?CarrierZone
    {
        for ($current = $place; $current !== null; $current = $current->parent) {
            $candidates = $zones->get(PlaceMatcher::normalize($current->name));
            if ($candidates) {
                // Même nom à plusieurs endroits : celle dont la zone parente porte le nom de la commune
                $parent = $current->parent ? PlaceMatcher::normalize($current->parent->name) : null;

                return $candidates->first(fn (CarrierZone $z) => $parent && PlaceMatcher::normalize((string) $z->parent_name) === $parent) ?? $candidates->first();
            }
        }

        return null;
    }

    /**
     * Zone du transporteur pour un lieu de livraison (le lieu lui-même, sinon son quartier parent, sa commune).
     */
    public function zoneForPlace(CarrierConnection $connection, ?int $placeId): ?CarrierZone
    {
        if ($placeId === null) {
            return null;
        }

        $place = Place::with('parent.parent')->find($placeId);
        for ($current = $place; $current !== null; $current = $current->parent) {
            $mapping = CarrierZoneMapping::where('connection_id', $connection->id)->where('place_id', $current->id)->first();
            if ($mapping?->carrier_zone_id) {
                return CarrierZone::find($mapping->carrier_zone_id);
            }
            if ($mapping && $mapping->matched_by === 'manual') {
                return null; // marqué « non couvert » par le marchand
            }
        }

        return null;
    }

    /**
     * @param  list<array{place_id: int, carrier_zone_id: ?int}>  $mappings
     */
    public function updateMappings(CarrierConnection $connection, array $mappings): void
    {
        $zoneIds = CarrierZone::where('connection_id', $connection->id)->pluck('id')->flip();
        $placeIds = $this->places->forStore($connection->store)->pluck('id')->flip();

        DB::transaction(function () use ($connection, $mappings, $zoneIds, $placeIds) {
            foreach ($mappings as $mapping) {
                if (! $placeIds->has($mapping['place_id']) || ($mapping['carrier_zone_id'] !== null && ! $zoneIds->has($mapping['carrier_zone_id']))) {
                    throw new BusinessRuleException('Correspondance invalide.', 'mappings');
                }
                CarrierZoneMapping::updateOrCreate(
                    ['connection_id' => $connection->id, 'place_id' => $mapping['place_id']],
                    ['carrier_zone_id' => $mapping['carrier_zone_id'], 'matched_by' => 'manual'],
                );
            }
        });
    }

    public function disconnect(CarrierConnection $connection): void
    {
        try {
            $this->driver($connection)->removeWebhook($connection);
        } catch (CarrierException) {
            // Transporteur injoignable : la connexion est quand même désactivée de notre côté
        }

        $connection->forceFill(['status' => 'disabled', 'api_key' => null, 'webhook_secret' => null, 'remote_webhook_id' => null])->save();
    }
}
