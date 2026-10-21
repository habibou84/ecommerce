<?php

namespace App\Services\Shipping;

use App\Exceptions\BusinessRuleException;
use App\Models\CarrierConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use App\Services\Catalog\DeliveryAppImporter;
use App\Services\Shipping\Drivers\DeliveryAppDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Annuaire des entreprises de livraison de Jibiat et connexion en un clic (contrat « directory » et
 * « connect-token » de habibou84/socle) : le marchand choisit une entreprise, autorise l'accès chez elle,
 * et la boutique reçoit sa clé d'API sans qu'il ait rien à copier.
 */
class CarrierDirectory
{
    private const CACHE_KEY = 'carrier-directory';

    private const STATE_TTL = 1800;

    public function __construct(
        private readonly DeliveryAppImporter $addresses,
        private readonly CarrierConnections $connections,
    ) {}

    /**
     * Entreprises de l'annuaire, celles déjà connectées marquées, celles de la ville de la boutique d'abord.
     *
     * @return array{companies: list<array<string, mixed>>, available: bool}
     */
    public function forStore(Store $store): array
    {
        $companies = $this->all();
        if ($companies === null) {
            return ['companies' => [], 'available' => false];
        }

        $connected = CarrierConnection::forStore($store->id)->where('driver', CarrierConnection::DELIVERY_APP)
            ->where('status', '!=', 'disabled')->pluck('base_url')
            ->map(fn (string $url) => mb_strtolower((string) parse_url($url, PHP_URL_HOST)))->all();
        $city = mb_strtolower(trim((string) $store->address));

        $list = collect($companies)->map(function (array $c) use ($connected, $city) {
            $host = mb_strtolower((string) parse_url($c['url'], PHP_URL_HOST));
            $local = $city !== '' && collect($c['cities'])->contains(fn (string $name) => str_contains($city, mb_strtolower($name)));

            return [...$c, 'connected' => in_array($host, $connected, true), 'near' => $local];
        })->sortByDesc(fn (array $c) => [$c['near'] ? 1 : 0, $c['stats']['deliveries_90d'] ?? 0])->values()->all();

        return ['companies' => $list, 'available' => true];
    }

    /**
     * Entreprise de l'annuaire (parrainage d'un nouvel e-commerçant), null si inconnue ou annuaire injoignable.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        return collect($this->all() ?? [])->firstWhere('slug', $slug);
    }

    /**
     * @return list<array<string, mixed>>|null null si l'annuaire ne répond pas
     */
    private function all(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::acceptJson()->timeout(8)->get(config('platform.delivery_app_directory_url'));
            $data = $response->successful() ? $response->json('data') : null;
        } catch (Throwable $e) {
            Log::warning('Annuaire Jibiat injoignable', ['error' => $e->getMessage()]);
            $data = null;
        }
        if (! is_array($data)) {
            return null;
        }

        // Seules les entreprises des domaines autorisés (jibiat.com) sont gardées
        $companies = collect($data)->filter(fn ($c) => is_array($c) && isset($c['slug'], $c['name'], $c['url'], $c['connect_url']) && $this->allowed($c['connect_url']))
            ->map(fn (array $c) => [
                'slug' => (string) $c['slug'],
                'name' => (string) $c['name'],
                'url' => (string) $c['url'],
                'connect_url' => (string) $c['connect_url'],
                'logo_url' => $c['logo_url'] ?? null,
                'pitch' => $c['pitch'] ?? null,
                'phone' => $c['phone'] ?? null,
                'cities' => array_values(array_filter((array) ($c['cities'] ?? []), 'is_string')),
                'zones_count' => (int) ($c['zones_count'] ?? 0),
                'ships_inland' => (bool) ($c['ships_inland'] ?? false),
                'price_from' => isset($c['price_from']) ? (int) $c['price_from'] : null,
                'accepts' => ($c['accepts'] ?? 'signup') === 'request' ? 'request' : 'signup',
                'stats' => [
                    'deliveries_90d' => (int) ($c['stats']['deliveries_90d'] ?? 0),
                    'success_rate' => isset($c['stats']['success_rate']) ? (float) $c['stats']['success_rate'] : null,
                    'average_delivery_hours' => isset($c['stats']['average_delivery_hours']) ? (float) $c['stats']['average_delivery_hours'] : null,
                ],
            ])->values()->all();

        Cache::put(self::CACHE_KEY, $companies, now()->addMinutes(10));

        return $companies;
    }

    private function allowed(string $url): bool
    {
        try {
            $this->addresses->apiUrl($url);

            return true;
        } catch (BusinessRuleException) {
            return false;
        }
    }

    /**
     * Début de la connexion : adresse de la page « Connecter » de l'entreprise (défi PKCE, état mémorisé).
     */
    public function start(Store $store, User $user, string $slug): string
    {
        $company = collect($this->all() ?? [])->firstWhere('slug', $slug)
            ?? throw new BusinessRuleException('Entreprise introuvable dans l\'annuaire : rechargez la page.', 'slug');

        // Offre sans entreprise de livraison possible : dit avant d'envoyer le marchand chez l'entreprise
        app(PlanLimits::class)->ensureCanAdd($store, 'carrier_connections');

        $verifier = Str::random(64);
        $state = Str::random(40);
        $redirect = $store->url('/gestion/livraisons/entreprises');

        Cache::put("carrier-connect:{$state}", [
            'store_id' => $store->id, 'user_id' => $user->id, 'slug' => $company['slug'], 'url' => $company['url'],
            'verifier' => $verifier, 'redirect_uri' => $redirect,
        ], self::STATE_TTL);

        $query = http_build_query(array_filter([
            'client_id' => DeliveryAppDriver::CLIENT_APP,
            'redirect_uri' => $redirect,
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'business_name' => $store->name,
            'phone' => $store->phone,
        ]));

        return $company['connect_url'].(str_contains($company['connect_url'], '?') ? '&' : '?').$query;
    }

    /**
     * Retour de la page « Connecter » : le code est échangé contre une clé, puis la connexion habituelle
     * (zones, webhook) est faite avec elle.
     */
    public function finish(Store $store, User $user, string $state, string $code): CarrierConnection
    {
        $pending = Cache::pull("carrier-connect:{$state}");
        if (! is_array($pending) || $pending['store_id'] !== $store->id || $pending['user_id'] !== $user->id) {
            throw new BusinessRuleException('Connexion expirée : touchez de nouveau « Connecter ».');
        }

        $tokenUrl = Str::beforeLast($this->addresses->apiUrl($pending['url']), '/v1').'/connect/token';

        try {
            $response = Http::acceptJson()->timeout(15)->withHeaders(['X-Client-App' => DeliveryAppDriver::CLIENT_APP])->post($tokenUrl, [
                'grant_type' => 'authorization_code',
                'client_id' => DeliveryAppDriver::CLIENT_APP,
                'code' => $code,
                'code_verifier' => $pending['verifier'],
                'redirect_uri' => $pending['redirect_uri'],
            ]);
        } catch (Throwable $e) {
            throw new BusinessRuleException('L\'entreprise de livraison ne répond pas : réessayez dans un instant.');
        }

        $key = $response->json('data.api_key');
        if (! $response->successful() || ! is_string($key)) {
            throw new BusinessRuleException($response->json('message') ?: 'Connexion refusée par l\'entreprise de livraison : recommencez.');
        }

        return $this->connections->connectDeliveryApp($store, $pending['url'], $key);
    }
}
