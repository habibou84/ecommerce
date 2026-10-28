<?php

namespace App\Services\Marketing;

use App\Exceptions\BusinessRuleException;
use App\Models\SocialAccount;
use App\Models\Store;
use App\Services\WhatsApp\WhatsAppCloud;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Pixel et catalogue Meta branchés en un clic : le marchand se connecte avec Facebook, choisit son compte Business,
 * un pixel et un catalogue (ou les fait créer), et l'application ajoute le lien du catalogue (mis à jour toutes les
 * heures), relie le pixel au catalogue et garde l'accès pour envoyer les achats par le serveur (API Conversions).
 * L'accès est gardé comme un compte « meta_ads » de la boutique.
 */
class MetaAds
{
    public const NETWORK = 'meta_ads';

    public const SCOPES = ['business_management', 'catalog_management', 'ads_management'];

    public const FEED_NAME = 'Jibiat Marchand';

    public function __construct(private readonly WhatsAppCloud $graph, private readonly CatalogFeed $feed) {}

    /**
     * Même adresse de retour que la connexion de la page Facebook (déjà déclarée chez Meta).
     */
    public function authorizeUrl(string $state): string
    {
        $params = ['client_id' => config('services.meta.app_id'), 'redirect_uri' => Tenancy::platformUrl('/reseaux/meta/retour'), 'state' => $state, 'response_type' => 'code'];
        // Configuration « Facebook Login for Business » avec un jeton d'utilisateur système : accès sans expiration
        $params += filled(config('services.meta.ads_config_id'))
            ? ['config_id' => config('services.meta.ads_config_id')]
            : ['scope' => implode(',', self::SCOPES)];

        return 'https://www.facebook.com/'.WhatsAppCloud::VERSION.'/dialog/oauth?'.http_build_query($params);
    }

    /**
     * Jeton tiré du code de connexion (de longue durée quand Meta le permet), avec sa date d'expiration.
     *
     * @return array{token: string, expires_at: ?string}
     */
    public function token(string $code): array
    {
        $app = ['client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret')];
        $token = (string) ($this->graph->graph('GET', 'oauth/access_token', null, $app + ['redirect_uri' => Tenancy::platformUrl('/reseaux/meta/retour'), 'code' => $code])['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Meta n\'a pas renvoyé de jeton.');
        }
        try {
            $token = (string) ($this->graph->graph('GET', 'oauth/access_token', null, $app + ['grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $token])['access_token'] ?? $token);
        } catch (RuntimeException) {
            // jeton d'utilisateur système : déjà sans expiration
        }

        $expires = null;
        try {
            $info = $this->graph->graph('GET', 'debug_token', $app['client_id'].'|'.$app['client_secret'], ['input_token' => $token])['data'] ?? [];
            $at = (int) ($info['expires_at'] ?? 0);
            $expires = $at > 0 ? Carbon::createFromTimestamp($at)->toIso8601String() : null;
        } catch (RuntimeException) {
            // date inconnue : on le saura à la première erreur
        }

        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function businesses(string $token): array
    {
        $rows = $this->graph->graph('GET', 'me/businesses', $token, ['fields' => 'id,name', 'limit' => 50])['data'] ?? [];

        return array_values(array_map(fn ($b) => ['id' => (string) $b['id'], 'name' => (string) ($b['name'] ?? '')], $rows));
    }

    /**
     * Pixels et catalogues du compte Business (les siens et ceux qu'on lui a partagés).
     *
     * @return array{pixels: list<array{id: string, name: string}>, catalogs: list<array{id: string, name: string, products: ?int}>}
     */
    public function assets(string $token, string $businessId): array
    {
        $list = function (array $edges, string $fields) use ($token, $businessId): array {
            $rows = [];
            foreach ($edges as $edge) {
                try {
                    $rows = array_merge($rows, $this->graph->graph('GET', $businessId.'/'.$edge, $token, ['fields' => $fields, 'limit' => 100])['data'] ?? []);
                } catch (RuntimeException) {
                    // partagés : facultatif
                }
            }

            return collect($rows)->unique('id')->values()->all();
        };

        return [
            'pixels' => array_map(fn ($p) => ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? '')], $list(['owned_pixels', 'client_pixels'], 'id,name')),
            'catalogs' => array_map(fn ($c) => ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? ''), 'products' => isset($c['product_count']) ? (int) $c['product_count'] : null],
                $list(['owned_product_catalogs', 'client_product_catalogs'], 'id,name,product_count')),
        ];
    }

    /**
     * Branche tout : pixel et catalogue (existants ou créés), lien du catalogue ajouté au catalogue, pixel relié au
     * catalogue, accès gardé pour l'API Conversions. Renvoie les avertissements (étapes facultatives qui ont échoué).
     *
     * @param  array{token: string, expires_at: ?string}  $access
     * @return list<string>
     */
    public function setup(Store $store, int $userId, array $access, array $business, string $pixel, string $catalog): array
    {
        $token = $access['token'];
        try {
            if ($pixel === 'new') {
                $pixel = (string) ($this->graph->graph('POST', $business['id'].'/adspixels', $token, ['name' => $store->name])['id'] ?? '');
            }
            if ($catalog === 'new') {
                $catalog = (string) ($this->graph->graph('POST', $business['id'].'/owned_product_catalogs', $token, ['name' => $store->name.' (Jibiat Marchand)'])['id'] ?? '');
            }
        } catch (RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage());
        }
        if ($pixel === '' || $catalog === '') {
            throw new BusinessRuleException('Meta n\'a pas créé le pixel ou le catalogue : réessayez.');
        }

        $warnings = [];
        try {
            $this->addFeed($store, $token, $catalog);
        } catch (RuntimeException $e) {
            $warnings[] = 'Le lien du catalogue n\'a pas pu être ajouté ('.$e->getMessage().'). Ajoutez-le à la main dans le gestionnaire de catalogue.';
        }
        try {
            $this->graph->graph('POST', $catalog.'/external_event_sources', $token, ['external_event_sources' => [$pixel]]);
        } catch (RuntimeException $e) {
            $warnings[] = 'Le pixel n\'a pas pu être relié au catalogue ('.$e->getMessage().'). Reliez-les dans le gestionnaire de catalogue › Sources d\'événements.';
        }

        $store->marketing = array_filter(array_merge($store->marketing ?? [], ['meta_pixel_id' => $pixel, 'meta_catalog_id' => $catalog, 'meta_business_id' => $business['id']]));
        $store->save();

        $account = SocialAccount::forStore($store->id)->firstOrNew(['store_id' => $store->id, 'network' => self::NETWORK]);
        $account->forceFill([
            'external_id' => $business['id'], 'name' => $business['name'], 'access_token' => $token, 'refresh_token' => null,
            'token_expires_at' => $access['expires_at'], 'page_id' => null, 'status' => 'active', 'last_error' => null, 'connected_by' => $userId,
        ])->save();

        return $warnings;
    }

    /**
     * Lien du catalogue de la boutique, relu par Meta toutes les heures (une seule fois par catalogue).
     */
    private function addFeed(Store $store, string $token, string $catalog): void
    {
        $url = $this->feed->url($store);
        $feeds = $this->graph->graph('GET', $catalog.'/product_feeds', $token, ['fields' => 'id,name,schedule', 'limit' => 100])['data'] ?? [];
        foreach ($feeds as $feed) {
            if (($feed['name'] ?? null) === self::FEED_NAME || ($feed['schedule']['url'] ?? null) === $url) {
                return;
            }
        }
        $this->graph->graph('POST', $catalog.'/product_feeds', $token, ['name' => self::FEED_NAME, 'schedule' => ['interval' => 'HOURLY', 'url' => $url]]);
    }

    public function account(Store $store): ?SocialAccount
    {
        return SocialAccount::forStore($store->id)->where('network', self::NETWORK)->first();
    }

    /**
     * Jeton pour l'API Conversions : celui collé à la main d'abord, sinon l'accès donné par la connexion.
     */
    public function conversionsToken(Store $store): ?string
    {
        if (filled($store->meta_capi_token)) {
            return $store->meta_capi_token;
        }
        $account = $this->account($store);

        return $account && $account->status === 'active' && ($account->token_expires_at === null || $account->token_expires_at->isFuture())
            ? $account->access_token : null;
    }

    public function disconnect(Store $store): void
    {
        $this->account($store)?->delete();
        $store->marketing = array_filter(array_diff_key($store->marketing ?? [], ['meta_catalog_id' => 1, 'meta_business_id' => 1])) ?: null;
        $store->save();
    }
}
