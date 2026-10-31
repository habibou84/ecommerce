<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Marketing\CatalogFeed;
use App\Services\Marketing\MetaAds;
use App\Services\Platform\Features;
use App\Services\Social\MetaSocial;
use App\Services\Social\SocialConnections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Suivi publicitaire de la boutique en ligne : pixels Meta et TikTok, Google Analytics, API Conversions de Meta.
 */
class TrackingController extends Controller
{
    public function show(): JsonResponse
    {
        $store = $this->currentStore();
        $meta = app(MetaAds::class)->account($store);

        return response()->json(['data' => [
            'meta_pixel_id' => $store->marketingId('meta_pixel_id'),
            'tiktok_pixel_id' => $store->marketingId('tiktok_pixel_id'),
            'ga4_id' => $store->marketingId('ga4_id'),
            'has_capi_token' => filled($store->meta_capi_token),
            // Achats envoyés par le serveur ces 30 derniers jours
            'conversions_sent' => Order::where('conversion_sent_at', '>=', now()->subDays(30))->count(),
            // Flux du catalogue pour Meta (Facebook, Instagram, WhatsApp) et Google Merchant
            // Catalogue Meta et branchement en un clic : seulement quand la console les a allumés
            'catalog_feed_url' => app(Features::class)->enabled('meta_catalog', $store) ? app(CatalogFeed::class)->url($store) : null,
            'catalog_items' => count(app(CatalogFeed::class)->rows($store)),
            // Connexion Facebook pour la publicité : pixel et catalogue branchés par l'application
            'meta_available' => MetaSocial::enabled() && app(Features::class)->enabled('meta_connect', $store),
            'meta_connection' => $meta ? [
                'business' => $meta->name,
                'catalog_id' => $store->marketingId('meta_catalog_id'),
                'expires_at' => $meta->token_expires_at?->toDateString(),
                'expired' => $meta->token_expires_at?->isPast() ?? false,
                'error' => $meta->last_error,
            ] : null,
            // Achats envoyés par le serveur grâce à la connexion (sans jeton collé à la main)
            'conversions_ready' => app(MetaAds::class)->conversionsToken($store) !== null,
        ]]);
    }

    /**
     * Nouveau lien du flux (l'ancien ne marche plus : à remplacer chez Meta).
     */
    public function renewFeed(CatalogFeed $feed): JsonResponse
    {
        $feed->token($this->currentStore(), true);

        return $this->show();
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meta_pixel_id' => ['nullable', 'regex:/^\d{6,20}$/'],
            'tiktok_pixel_id' => ['nullable', 'regex:/^[A-Z0-9]{10,30}$/'],
            'ga4_id' => ['nullable', 'regex:/^G-[A-Z0-9]{4,20}$/'],
            'meta_capi_token' => ['nullable', 'string', 'max:500'],
            'remove_capi_token' => ['sometimes', 'boolean'],
        ], [
            'meta_pixel_id.regex' => 'L\'identifiant du pixel Meta ne contient que des chiffres.',
            'tiktok_pixel_id.regex' => 'Identifiant de pixel TikTok invalide (lettres majuscules et chiffres).',
            'ga4_id.regex' => 'L\'identifiant Google Analytics commence par G-.',
        ]);

        $store = $this->currentStore();
        // Catalogue et compte Business de la connexion Facebook gardés
        $store->marketing = array_filter(array_merge($store->marketing ?? [], [
            'meta_pixel_id' => $data['meta_pixel_id'] ?? null,
            'tiktok_pixel_id' => $data['tiktok_pixel_id'] ?? null,
            'ga4_id' => $data['ga4_id'] ?? null,
        ])) ?: null;
        if ($request->boolean('remove_capi_token')) {
            $store->meta_capi_token = null;
        } elseif (filled($data['meta_capi_token'] ?? null)) {
            $store->meta_capi_token = $data['meta_capi_token'];
        }
        $store->save();

        return $this->show();
    }

    public function metaConnect(Request $request, SocialConnections $connections): JsonResponse
    {
        return response()->json(['url' => $connections->adsAuthorizeUrl($this->currentStore(), $request->user())]);
    }

    /**
     * Après la connexion : comptes Business, puis pixels et catalogues de celui choisi.
     */
    public function metaPending(Request $request, string $key, MetaAds $ads): JsonResponse
    {
        $pending = $this->pending($request, $key);
        $data = ['businesses' => $pending['businesses'], 'assets' => null];
        if ($business = $this->business($pending, (string) $request->query('business'))) {
            try {
                $data['assets'] = $ads->assets($pending['access']['token'], $business['id']);
            } catch (RuntimeException $e) {
                throw new BusinessRuleException($e->getMessage());
            }
        }

        return response()->json(['data' => $data]);
    }

    public function metaSetup(Request $request, string $key, MetaAds $ads): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'string', 'max:30'],
            'pixel_id' => ['required', 'regex:/^(new|\d{6,20})$/'],
            'catalog_id' => ['required', 'regex:/^(new|\d{6,20})$/'],
        ]);
        $pending = $this->pending($request, $key);
        $business = $this->business($pending, $data['business_id']) ?? throw new BusinessRuleException('Compte Business introuvable : reconnectez Facebook.', 'business_id');
        // Seulement un pixel ou un catalogue de ce compte Business (pas un identifiant tapé au hasard)
        $assets = $ads->assets($pending['access']['token'], $business['id']);
        foreach (['pixel_id' => 'pixels', 'catalog_id' => 'catalogs'] as $field => $list) {
            if ($data[$field] !== 'new' && ! collect($assets[$list])->contains('id', $data[$field])) {
                throw new BusinessRuleException('Choix introuvable dans ce compte Business.', $field);
            }
        }

        $warnings = $ads->setup($this->currentStore(), $pending['user'], $pending['access'], $business, $data['pixel_id'], $data['catalog_id']);
        Cache::forget('meta-ads:'.$key);

        return response()->json(['data' => $this->show()->getData(true)['data'], 'warnings' => $warnings]);
    }

    public function metaDisconnect(MetaAds $ads): JsonResponse
    {
        $ads->disconnect($this->currentStore());

        return $this->show();
    }

    /**
     * Connexion en attente, seulement pour la boutique et la personne qui l'ont lancée.
     *
     * @return array{store: int, user: int, access: array{token: string, expires_at: ?string}, businesses: list<array{id: string, name: string}>}
     */
    private function pending(Request $request, string $key): array
    {
        $pending = Cache::get('meta-ads:'.$key);
        if (! is_array($pending) || $pending['store'] !== $this->currentStore()->id || $pending['user'] !== $request->user()->id) {
            throw new BusinessRuleException('La connexion à Facebook a expiré : recommencez.');
        }

        return $pending;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function business(array $pending, string $id): ?array
    {
        return $id === '' ? null : collect($pending['businesses'])->firstWhere('id', $id);
    }
}
