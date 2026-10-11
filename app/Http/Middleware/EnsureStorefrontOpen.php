<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boutique en ligne fermée (pas encore ouverte par le marchand) : page d'attente, sauf pour l'aperçu
 * (cookie « apercu » posé par un lien signé depuis la gestion).
 */
class EnsureStorefrontOpen
{
    public const PREVIEW_COOKIE = 'apercu';

    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = $this->tenancy->store();

        if ($store->storefront_enabled || self::previewing($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Cette boutique n\'est pas encore ouverte.'], 404);
        }

        return response()->view('storefront.coming-soon', ['store' => $store], 200);
    }

    public static function previewing(Request $request): bool
    {
        $until = $request->cookie(self::PREVIEW_COOKIE);

        return is_string($until) && ctype_digit($until) && (int) $until > time();
    }
}
