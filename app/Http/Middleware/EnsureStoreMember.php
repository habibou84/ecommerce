<?php

namespace App\Http\Middleware;

use App\Services\Console\SupportSessions;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La personne connectée est active et fait partie (activement) de la boutique de l'adresse.
 * Une session ouverte sur une boutique ne donne jamais accès à une autre.
 */
class EnsureStoreMember
{
    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $store = $this->tenancy->store();

        if ($user === null || $store === null) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'Compte suspendu.'], 403);
        }

        $membership = $user->activeMembershipIn($store);
        if ($membership === null) {
            // Administrateur de la plateforme pendant une session de support (durée limitée)
            $support = app(SupportSessions::class)->ongoingFor($user, $store);
            if ($support === null) {
                return response()->json(['message' => 'Vous n\'avez pas accès à cet espace marchand.'], 403);
            }
            $request->attributes->set('support_session', $support);

            return $next($request);
        }

        // Au plus une écriture par quart d'heure
        if ($membership->last_seen_at === null || $membership->last_seen_at->lt(now()->subMinutes(15))) {
            $membership->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}
