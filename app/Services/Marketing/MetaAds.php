<?php

namespace App\Services\Marketing;

use App\Exceptions\BusinessRuleException;
use App\Models\SocialAccount;
use App\Models\Store;
use App\Services\WhatsApp\WhatsAppCloud;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    public const SCOPES = ['business_management', 'catalog_management', 'ads_management', 'pages_show_list', 'pages_read_engagement'];

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
        try {
            $rows = $this->graph->graph('GET', 'me/businesses', $token, ['fields' => 'id,name', 'limit' => 50])['data'] ?? [];
        } catch (RuntimeException $e) {
            // Jeton d'utilisateur système (configuration « Facebook Login for Business ») : me/businesses est refusé
            // (#100 Missing Permission) ; les comptes Business choisis dans la fenêtre de Facebook sont dans le jeton
            $rows = $this->grantedBusinesses($token);
            if ($rows === []) {
                throw new RuntimeException($e->getMessage().' : vérifiez que la configuration Meta demande business_management et que ce compte Business a été coché dans la fenêtre de Facebook.');
            }
        }

        return array_values(array_map(fn ($b) => ['id' => (string) $b['id'], 'name' => (string) ($b['name'] ?? '')], $rows));
    }

    /**
     * Comptes Business autorisés dans le jeton : cibles de business_management (debug_token), sinon le compte Business
     * du client pour un utilisateur système.
     *
     * @return list<array{id: string, name: string}>
     */
    private function grantedBusinesses(string $token): array
    {
        $ids = [];
        try {
            $app = config('services.meta.app_id').'|'.config('services.meta.app_secret');
            $info = $this->graph->graph('GET', 'debug_token', $app, ['input_token' => $token])['data'] ?? [];
            foreach ($info['granular_scopes'] ?? [] as $scope) {
                if (($scope['scope'] ?? null) === 'business_management') {
                    $ids = array_merge($ids, array_map('strval', $scope['target_ids'] ?? []));
                }
            }
        } catch (RuntimeException) {
            // essai suivant
        }
        if ($ids === []) {
            try {
                $client = (string) ($this->graph->graph('GET', 'me', $token, ['fields' => 'client_business_id'])['client_business_id'] ?? '');
                $ids = $client !== '' ? [$client] : [];
            } catch (RuntimeException) {
                return [];
            }
        }

        return array_values(array_map(function (string $id) use ($token) {
            try {
                $name = (string) ($this->graph->graph('GET', $id, $token, ['fields' => 'id,name'])['name'] ?? '');
            } catch (RuntimeException) {
                $name = '';
            }

            return ['id' => $id, 'name' => $name !== '' ? $name : 'Compte Business '.$id];
        }, array_unique($ids)));
    }

    /**
     * Pixels et catalogues du compte Business (les siens et ceux qu'on lui a partagés), plus ceux choisis dans la
     * fenêtre de Facebook : avec la connexion « Facebook Login for Business », le jeton ne peut pas lister le compte
     * Business, mais les catalogues et comptes publicitaires autorisés sont dans le jeton (debug_token).
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

            return $rows;
        };
        $catalogs = $list(['owned_product_catalogs', 'client_product_catalogs'], 'id,name,product_count');
        $pixels = $list(['owned_pixels', 'client_pixels'], 'id,name');

        // Catalogues autorisés dans la fenêtre de Facebook
        foreach ($this->granted($token, 'catalog_management') as $id) {
            try {
                $catalogs[] = $this->graph->graph('GET', $id, $token, ['fields' => 'id,name,product_count']);
            } catch (RuntimeException) {
                // catalogue illisible avec ce jeton : ignoré
            }
        }
        // Pixels des comptes publicitaires autorisés (le pixel d'un compte publicitaire est utilisable pour la boutique)
        foreach ($this->granted($token, 'ads_management') as $id) {
            $account = str_starts_with($id, 'act_') ? $id : 'act_'.$id;
            try {
                $pixels = array_merge($pixels, $this->graph->graph('GET', $account.'/adspixels', $token, ['fields' => 'id,name', 'limit' => 100])['data'] ?? []);
            } catch (RuntimeException) {
                // compte publicitaire illisible : ignoré
            }
        }

        return [
            'pixels' => collect($pixels)->unique('id')->map(fn ($p) => ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? '')])->values()->all(),
            'catalogs' => collect($catalogs)->unique('id')->map(fn ($c) => ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? ''), 'products' => isset($c['product_count']) ? (int) $c['product_count'] : null])->values()->all(),
        ];
    }

    /**
     * Identifiants choisis dans la fenêtre de Facebook pour une autorisation (cibles de l'autorisation dans le jeton).
     *
     * @return list<string>
     */
    private function granted(string $token, string $scope): array
    {
        return collect($this->tokenInfo($token)['granular_scopes'] ?? [])->where('scope', $scope)->flatMap(fn ($g) => $g['target_ids'] ?? [])->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    /** Ce que Meta dit du jeton (autorisations reçues et éléments choisis dans la fenêtre de Facebook) */
    private function tokenInfo(string $token): array
    {
        try {
            $app = config('services.meta.app_id').'|'.config('services.meta.app_secret');

            return $this->graph->graph('GET', 'debug_token', $app, ['input_token' => $token])['data'] ?? [];
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * Autorisations que Facebook a vraiment données (sans le jeton), pour dire pourquoi la liste des catalogues et des
     * pixels est vide : autorisation absente de la configuration Facebook, ou rien coché dans la fenêtre.
     *
     * @return array{known: bool, scopes: list<string>, missing: list<string>, catalogs_chosen: int, ad_accounts_chosen: int}
     */
    public function permissions(string $token): array
    {
        $info = $this->tokenInfo($token);
        $scopes = array_values(array_map('strval', $info['scopes'] ?? []));
        $granular = collect($info['granular_scopes'] ?? []);
        // Autorisations granulaires (choisies pour des éléments précis) : comptées comme reçues
        $have = array_unique([...$scopes, ...$granular->pluck('scope')->map(fn ($x) => (string) $x)->all()]);

        return [
            'known' => $info !== [],
            'scopes' => array_values($have),
            'missing' => array_values(array_diff(['business_management', 'catalog_management', 'ads_management'], $have)),
            'catalogs_chosen' => count($this->granted($token, 'catalog_management')),
            'ad_accounts_chosen' => count($this->granted($token, 'ads_management')),
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
        // Le catalogue d'abord : s'il est refusé, aucun pixel n'a été créé pour rien
        if ($catalog === 'new') {
            try {
                $catalog = (string) ($this->graph->graph('POST', $business['id'].'/owned_product_catalogs', $token, ['name' => $store->name.' (Jibiat Marchand)'])['id'] ?? '');
            } catch (RuntimeException $e) {
                throw new BusinessRuleException('Meta refuse de créer le catalogue pour ce compte Business ('.$e->getMessage().'). Choisissez un catalogue existant, ou créez-le dans le Gestionnaire de commerce de Meta, puis revenez ici le choisir.');
            }
        }
        if ($pixel === 'new') {
            $pixel = $this->createPixel($store, $token, $business['id']);
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
     * Nouveau pixel : au nom du compte Business, sinon sur un de ses comptes publicitaires (certains comptes Business,
     * reliés par « Facebook Login for Business », refusent la création directe : « Unsupported post request »).
     */
    private function createPixel(Store $store, string $token, string $businessId): string
    {
        try {
            return (string) ($this->graph->graph('POST', $businessId.'/adspixels', $token, ['name' => $store->name])['id'] ?? '');
        } catch (RuntimeException $e) {
            $first = $e;
        }
        $accounts = [];
        foreach ([$businessId.'/owned_ad_accounts', $businessId.'/client_ad_accounts', 'me/adaccounts'] as $edge) {
            try {
                $rows = $this->graph->graph('GET', $edge, $token, ['fields' => 'id,account_status', 'limit' => 50])['data'] ?? [];
            } catch (RuntimeException) {
                continue;
            }
            // Comptes actifs d'abord (account_status 1)
            usort($rows, fn ($a, $b) => (int) (($b['account_status'] ?? 0) === 1) <=> (int) (($a['account_status'] ?? 0) === 1));
            $accounts = array_merge($accounts, array_column($rows, 'id'));
        }
        foreach (array_unique($accounts) as $account) {
            try {
                $id = (string) ($this->graph->graph('POST', $account.'/adspixels', $token, ['name' => $store->name])['id'] ?? '');
                if ($id !== '') {
                    return $id;
                }
            } catch (RuntimeException) {
                // compte suivant
            }
        }

        throw new BusinessRuleException('Meta refuse de créer le pixel pour ce compte Business ('.$first->getMessage().'). Choisissez un pixel existant, ou créez-le dans le Gestionnaire d\'événements de Meta (Sources de données › Ajouter › Web), puis revenez ici le choisir.');
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

    /**
     * Vérification du pixel : événements reçus par Meta ces 7 derniers jours (vues, paniers, achats…), relus au plus
     * une fois par heure. Null si Meta ne répond pas ou si la boutique n'est pas reliée.
     *
     * @return array<string, int>|null
     */
    public function pixelStats(Store $store): ?array
    {
        $pixel = $store->marketingId('meta_pixel_id');
        $account = $this->account($store);
        if ($pixel === null || $account === null || $account->status !== 'active') {
            return null;
        }

        return Cache::remember('meta-pixel-stats:'.$store->id.':'.$pixel, now()->addHour(), function () use ($pixel, $account) {
            try {
                $rows = $this->graph->graph('GET', $pixel.'/stats', $account->access_token, ['aggregation' => 'event', 'start_time' => now()->subDays(7)->timestamp])['data'] ?? [];
            } catch (RuntimeException) {
                return null;
            }
            $counts = [];
            foreach ($rows as $row) {
                foreach ($row['data'] ?? [] as $point) {
                    $event = (string) ($point['value'] ?? '');
                    if ($event !== '') {
                        $counts[$event] = ($counts[$event] ?? 0) + (int) ($point['count'] ?? 0);
                    }
                }
            }

            return $counts;
        });
    }

    public function disconnect(Store $store): void
    {
        $this->account($store)?->delete();
        $store->marketing = array_filter(array_diff_key($store->marketing ?? [], ['meta_catalog_id' => 1, 'meta_business_id' => 1])) ?: null;
        $store->save();
    }
}
