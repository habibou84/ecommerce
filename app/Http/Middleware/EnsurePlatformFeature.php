<?php

namespace App\Http\Middleware;

use App\Services\Platform\Features;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fonction éteinte dans la console (ou en test pour d'autres boutiques) : l'appel est refusé.
 */
class EnsurePlatformFeature
{
    public function __construct(private readonly Features $features, private readonly Tenancy $tenancy) {}

    /**
     * « feature:live,live_screens » : toutes ces fonctions ; « feature:any,social_meta,social_tiktok » : au moins une.
     */
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        $any = ($keys[0] ?? null) === 'any';
        $open = collect($any ? array_slice($keys, 1) : $keys)->map(fn ($key) => $this->features->enabled($key, $this->tenancy->store()));
        if ($any ? ! $open->contains(true) : $open->contains(false)) {
            return response()->json(['message' => 'Cette fonction n\'est pas encore disponible.', 'code' => 'feature_off'], 403);
        }

        return $next($request);
    }
}
