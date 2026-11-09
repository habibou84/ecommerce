<?php

namespace App\Services\Accounts;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fermeture des sessions d'un compte, qu'elles soient dans la base ou dans Redis : chaque session retient la version
 * du compte au moment de la connexion (EnsureSessionCurrent), et une session plus ancienne que la version actuelle
 * est déconnectée à sa prochaine requête. Les cookies « se souvenir de moi » sont annulés en même temps.
 */
class Sessions
{
    public const KEY = 'session_version';

    /** Ferme toutes les sessions du compte, sauf $keep (la session de la personne qui agit sur son propre compte). */
    public static function revoke(User $user, ?Session $keep = null): void
    {
        $user->forceFill([
            'session_version' => (int) $user->session_version + 1,
            'remember_token' => Str::random(60),
        ])->save();
        if ($keep !== null) {
            self::stamp($user, $keep);
        }
        // Sessions dans la base : supprimées tout de suite (sans effet si elles sont dans Redis)
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)
            ->when($keep !== null, fn ($q) => $q->where('id', '!=', $keep->getId()))->delete();
    }

    /** La session appartient à la version actuelle du compte. */
    public static function stamp(User $user, Session $session): void
    {
        $session->put(self::KEY, $user->id.':'.(int) $user->session_version);
    }

    /** La session est encore valable pour ce compte (une session sans marque est marquée à la volée). */
    public static function current(User $user, Session $session): bool
    {
        $mark = $session->get(self::KEY);
        if (! is_string($mark) || ! str_starts_with($mark, $user->id.':')) {
            self::stamp($user, $session);

            return true;
        }

        return $mark === $user->id.':'.(int) $user->session_version;
    }
}
