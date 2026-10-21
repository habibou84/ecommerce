<?php

namespace App\Services\Social;

use App\Exceptions\BusinessRuleException;
use App\Models\SocialAccount;
use App\Models\Store;
use App\Models\User;
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
    public function __construct(private readonly MetaSocial $meta, private readonly TikTokSocial $tiktok) {}

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
     * Retour de la fenêtre de connexion : adresse de la gestion où revenir.
     */
    public function callback(string $provider, string $state, ?string $code, ?string $error): string
    {
        $data = $this->state($state, $provider);
        $store = Store::findOrFail($data['store']);
        $back = $store->url('/gestion/marketing/reseaux');
        if ($code === null || $code === '') {
            return $back.'?erreur='.rawurlencode($error ?: 'Connexion annulée.');
        }

        try {
            // Le compte n'est enregistré qu'une fois confirmé dans la gestion, par la personne qui a lancé la connexion :
            // un lien de connexion envoyé à quelqu'un d'autre ne relie pas son compte à cette boutique
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
