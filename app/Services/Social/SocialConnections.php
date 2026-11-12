<?php

namespace App\Services\Social;

use App\Exceptions\BusinessRuleException;
use App\Models\SocialAccount;
use App\Models\Store;
use App\Models\User;
use App\Services\Marketing\MetaAds;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Connexion des réseaux de la boutique. Meta et TikTok ne renvoient qu'à une adresse déclarée chez eux : la fenêtre
 * de connexion revient sur le domaine de la plateforme, avec un « state » chiffré qui dit pour quelle boutique.
 * Facebook : la personne choisit ensuite la page (et le compte Instagram lié) depuis la gestion de la boutique.
 */
class SocialConnections
{
    public function __construct(private readonly MetaSocial $meta, private readonly TikTokSocial $tiktok, private readonly MetaAds $ads) {}

    public function authorizeUrl(Store $store, User $user, string $provider): string
    {
        $enabled = $provider === 'meta' ? MetaSocial::enabled() : TikTokSocial::enabled();
        if (! $enabled) {
            throw new BusinessRuleException('La connexion à '.($provider === 'meta' ? 'Facebook et Instagram' : 'TikTok').' n\'est pas encore activée sur la plateforme.');
        }
        $state = Crypt::encryptString(json_encode(['store' => $store->id, 'user' => $user->id, 'provider' => $provider, 'exp' => now()->addMinutes(30)->timestamp]));

        return $provider === 'meta' ? $this->meta->authorizeUrl($state) : $this->tiktok->authorizeUrl($state);
    }

    /**
     * Connexion Facebook pour la publicité (pixel et catalogue) : même fenêtre et même adresse de retour que la page,
     * autres autorisations ; le « state » dit à quoi elle sert.
     */
    /**
     * @param  string  $back  page où revenir : 'boost' (menu Boost) ou 'suivi' (Suivi publicitaire)
     * @param  ?string  $relay  application installée : clé sous laquelle déposer le résultat (Facebook ouvert par-dessus
     *                          l'application, qui le récupère sans être quittée)
     */
    public function adsAuthorizeUrl(Store $store, User $user, string $back = 'suivi', ?string $relay = null, bool $app = false): string
    {
        if (! MetaSocial::enabled()) {
            throw new BusinessRuleException('La connexion à Facebook n\'est pas encore activée sur la plateforme.');
        }
        $state = Crypt::encryptString(json_encode(['store' => $store->id, 'user' => $user->id, 'provider' => 'meta', 'purpose' => 'ads',
            'back' => $back, 'relay' => $relay, 'app' => $app, 'exp' => now()->addMinutes(30)->timestamp]));

        return $this->ads->authorizeUrl($state);
    }

    /** Pages de retour d'une connexion pour la pub */
    public const ADS_BACK = ['boost' => '/gestion/boost', 'suivi' => '/gestion/marketing/suivi'];

    /**
     * Retour de la fenêtre de connexion : adresse de la gestion où revenir. Connexion pour la pub ouverte depuis
     * l'application installée : le résultat (« meta=… » ou « erreur=… ») est déposé sous la clé du relais, et null est
     * renvoyé (la fenêtre affiche « retourne dans l'application »).
     */
    /** Dernier retour relayé : lancé depuis l'application Android (la page de fin propose d'y retourner) */
    public bool $relayApp = false;

    public function callback(string $provider, string $state, ?string $code, ?string $error): ?string
    {
        $this->relayApp = false;
        $data = $this->state($state, $provider);
        $store = Store::findOrFail($data['store']);
        $ads = ($data['purpose'] ?? null) === 'ads';
        if ($ads && ! empty($data['relay'])) {
            $url = $this->adsCallback($store, $data, $code, $error, '');
            Cache::put('meta-relay:'.$data['relay'], ['store' => $store->id, 'result' => ltrim($url, '?')], now()->addMinutes(30));
            $this->relayApp = (bool) ($data['app'] ?? false);

            return null;
        }
        if ($ads) {
            return $this->adsCallback($store, $data, $code, $error, $store->url(self::ADS_BACK[$data['back'] ?? 'suivi'] ?? self::ADS_BACK['suivi']));
        }
        $back = $store->url('/gestion/marketing/reseaux');
        if ($code === null || $code === '') {
            return $back.'?erreur='.rawurlencode($error ?: 'Connexion annulée.');
        }

        try {
            if ($provider === 'tiktok') {
                $account = $this->tiktok->connect($code);
                $key = Str::random(40);
                Cache::put('social-tiktok:'.$key, ['store' => $store->id, 'user' => $data['user'], 'account' => $account], now()->addMinutes(20));

                return $back.'?tiktok='.$key;
            }

            $pages = $this->meta->pages($code);
            if ($pages === []) {
                return $back.'?erreur='.rawurlencode('Aucune page Facebook trouvée : créez une page ou cochez-la dans la fenêtre de Facebook.');
            }
            $key = Str::random(40);
            Cache::put('social-pages:'.$key, ['store' => $store->id, 'user' => $data['user'], 'pages' => $pages], now()->addMinutes(20));

            return $back.'?pages='.$key;
        } catch (RuntimeException $e) {
            return $back.'?erreur='.rawurlencode($e->getMessage());
        }
    }

    /**
     * Connexion pour la pub : le compte n'est enregistré qu'une fois confirmé dans la gestion, par la personne qui a
     * lancé la connexion (un lien envoyé à quelqu'un d'autre ne relie pas son compte à cette boutique).
     *
     * @param  array<string, mixed>  $data
     */
    private function adsCallback(Store $store, array $data, ?string $code, ?string $error, string $back): string
    {
        if ($code === null || $code === '') {
            return $back.'?erreur='.rawurlencode($error ?: 'Connexion annulée.');
        }
        // Le même retour peut arriver deux fois (application Facebook, Chrome puis l'application, rechargement) : Meta
        // n'accepte un code qu'une fois, le résultat du premier passage est donc repris (« code déjà utilisé » sinon)
        $done = 'meta-code:'.hash('sha256', $code);
        if (is_string($previous = Cache::get($done))) {
            return $back.$previous;
        }
        try {
            $access = $this->ads->token($code);
            $businesses = $this->ads->businesses($access['token']);
            if ($businesses === []) {
                return $back.'?erreur='.rawurlencode('Aucun compte Business trouvé : créez-en un sur business.facebook.com, ou cochez-le dans la fenêtre de Facebook.');
            }
            $key = Str::random(40);
            Cache::put('meta-ads:'.$key, ['store' => $store->id, 'user' => $data['user'], 'access' => $access, 'businesses' => $businesses], now()->addMinutes(30));
            Cache::put($done, '?meta='.$key, now()->addMinutes(30));

            return $back.'?meta='.$key;
        } catch (RuntimeException $e) {
            return $back.'?erreur='.rawurlencode($e->getMessage());
        }
    }

    /**
     * Résultat d'une connexion ouverte depuis l'application installée (« meta=… » ou « erreur=… »), lu une fois.
     */
    public function relayed(Store $store, string $relay): ?string
    {
        $data = Cache::get('meta-relay:'.$relay);
        if (! is_array($data) || $data['store'] !== $store->id) {
            return null;
        }
        Cache::forget('meta-relay:'.$relay);

        return $data['result'];
    }

    /**
     * Pages proposées après la connexion Facebook (sans les jetons).
     *
     * @return list<array{id: string, name: string, instagram: ?array{id: string, name: string}}>
     */
    public function pendingPages(Store $store, User $user, string $key): array
    {
        return array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'instagram' => $p['instagram']], $this->pending($store, $user, $key)['pages']);
    }

    /**
     * Compte TikTok autorisé : enregistré une fois confirmé par la personne qui a lancé la connexion.
     */
    public function confirmTikTok(Store $store, User $user, string $key): SocialAccount
    {
        $pending = Cache::get('social-tiktok:'.$key);
        if (! is_array($pending) || $pending['store'] !== $store->id || $pending['user'] !== $user->id) {
            throw new BusinessRuleException('La connexion à TikTok a expiré : recommencez.');
        }
        Cache::forget('social-tiktok:'.$key);
        $account = $pending['account'];

        return $this->save($store, $user->id, 'tiktok', $account['open_id'], $account['name'], $account['access_token'], $account['refresh_token'], now()->addSeconds($account['expires_in']));
    }

    /**
     * Page choisie : la page Facebook et son compte Instagram professionnel sont connectés, les messages abonnés.
     *
     * @return list<SocialAccount>
     */
    public function choosePage(Store $store, User $user, string $key, string $pageId): array
    {
        $pending = $this->pending($store, $user, $key);
        $page = collect($pending['pages'])->firstWhere('id', $pageId) ?? throw new BusinessRuleException('Page introuvable : reconnectez Facebook.');

        try {
            $this->meta->subscribe($page['id'], $page['access_token']);
        } catch (RuntimeException) {
            // la publication marche sans ; les messages arriveront une fois l'autorisation accordée
        }
        $accounts = [$this->save($store, $pending['user'], 'facebook', $page['id'], $page['name'], $page['access_token'])];
        if ($page['instagram']) {
            $accounts[] = $this->save($store, $pending['user'], 'instagram', $page['instagram']['id'], $page['instagram']['name'], $page['access_token'], pageId: $page['id']);
        }
        Cache::forget('social-pages:'.$key);

        return $accounts;
    }

    private function save(Store $store, int $userId, string $network, string $externalId, string $name, string $token, ?string $refresh = null, $expires = null, ?string $pageId = null): SocialAccount
    {
        $account = SocialAccount::forStore($store->id)->firstOrNew(['store_id' => $store->id, 'network' => $network]);
        $account->forceFill([
            'external_id' => $externalId, 'name' => $name, 'access_token' => $token, 'refresh_token' => $refresh, 'token_expires_at' => $expires,
            'page_id' => $pageId, 'status' => 'active', 'last_error' => null, 'connected_by' => $userId,
        ])->save();

        return $account;
    }

    /**
     * @return array{store: int, user: int, pages: list<array<string, mixed>>}
     */
    private function pending(Store $store, User $user, string $key): array
    {
        $pending = Cache::get('social-pages:'.$key);
        if (! is_array($pending) || $pending['store'] !== $store->id || $pending['user'] !== $user->id) {
            throw new BusinessRuleException('La connexion à Facebook a expiré : recommencez.');
        }

        return $pending;
    }

    /**
     * @return array{store: int, user: int, provider: string, exp: int}
     */
    private function state(string $state, string $provider): array
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException) {
            $data = null;
        }
        if (! is_array($data) || ($data['provider'] ?? null) !== $provider || ($data['exp'] ?? 0) < now()->timestamp) {
            abort(403, 'Lien de connexion expiré : recommencez depuis votre espace marchand.');
        }

        return $data;
    }
}
