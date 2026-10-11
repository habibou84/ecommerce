<?php

namespace App\Services\Accounts;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Passage d'une adresse à une autre en restant connecté : la session est liée au nom d'hôte, donc une
 * connexion sur jibiat-marchand.com (création de boutique, choix de boutique) ou sur une autre boutique ne
 * vaut rien sur {slug}.jibiat-marchand.com. On remet un lien à usage unique, valable deux minutes.
 */
class LoginHandoff
{
    public const TTL_SECONDS = 120;

    public function url(User $user, Store $store, string $redirect = '/gestion'): string
    {
        $nonce = Str::random(48);
        Cache::put(self::key($nonce), ['user_id' => $user->id, 'store_id' => $store->id, 'redirect' => $redirect], self::TTL_SECONDS);

        return $store->url('/auth/relais?'.http_build_query(['jeton' => $nonce]));
    }

    /**
     * Consomme le lien (une seule fois). Renvoie la personne et la page de destination si le lien est valable
     * pour cette boutique.
     *
     * @return array{0: User, 1: string}|null
     */
    public function consume(string $nonce, Store $store): ?array
    {
        $data = Cache::pull(self::key($nonce));

        if (! is_array($data) || $data['store_id'] !== $store->id) {
            return null;
        }

        $user = User::find($data['user_id']);

        return $user ? [$user, $data['redirect']] : null;
    }

    private static function key(string $nonce): string
    {
        return 'login-handoff:'.hash('sha256', $nonce);
    }
}
