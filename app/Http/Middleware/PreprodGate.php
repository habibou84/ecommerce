<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Préproduction fermée au public : avant toute page, un code d'accès (PREPROD_ACCESS_CODE), saisi une fois puis
 * mémorisé 30 jours sur la plateforme et toutes les boutiques de test. Restent ouverts : api.* (notifications de
 * Meta, des transporteurs et des prestataires de paiement) et la sonde /up. Jamais actif en production.
 */
class PreprodGate
{
    public const COOKIE = 'jm_preprod';

    public const PATH = '_acces-preproduction';

    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $code = (string) config('platform.preprod_access_code');
        if ($code === '' || app()->isProduction() || $this->tenancy->zone() === Tenancy::API || $request->is('up')) {
            return $next($request);
        }

        $pass = hash_hmac('sha256', $code, (string) config('app.key'));
        if (hash_equals($pass, (string) $request->cookies->get(self::COOKIE)) || $this->maintenanceBypass($request)) {
            return $next($request);
        }

        if ($request->is(self::PATH) && $request->isMethod('POST')) {
            return $this->check($request, $code, $pass);
        }
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Préproduction : saisissez le code d\'accès.'], 403);
        }

        return $this->form($request->getRequestUri());
    }

    /**
     * Pendant une mise à jour (mode maintenance), le script deploy/update.sh vérifie le site avec le cookie de
     * passage de la maintenance (secret aléatoire, valable le temps de la mise à jour) : il voit les pages.
     */
    private function maintenanceBypass(Request $request): bool
    {
        $cookie = $request->cookies->get('laravel_maintenance');
        if ($cookie === null || ! app()->isDownForMaintenance()) {
            return false;
        }
        $secret = app()->maintenanceMode()->data()['secret'] ?? null;

        return is_string($secret) && MaintenanceModeBypassCookie::isValid((string) $cookie, $secret);
    }

    private function check(Request $request, string $code, string $pass): Response
    {
        $key = 'preprod-gate:'.$request->ip();
        $back = $this->back((string) $request->input('retour'));
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->form($back, 'Trop d\'essais. Réessayez dans une minute.', 429);
        }
        if (! hash_equals($code, (string) $request->input('code'))) {
            RateLimiter::hit($key, 60);

            return $this->form($back, 'Code incorrect.', 403);
        }
        RateLimiter::clear($key);

        // Valable sur la plateforme et toutes les boutiques de test (*.domaine), 30 jours
        $domain = Tenancy::domain();
        $cookieDomain = str_contains($domain, '.') && str_ends_with($request->getHost(), $domain) ? '.'.$domain : null;

        return redirect($back)->withCookie(Cookie::create(self::COOKIE, $pass, now()->addDays(30), '/', $cookieDomain, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
    }

    // Retour seulement vers une page de ce site
    private function back(string $path): string
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_starts_with($path, '/'.self::PATH) ? $path : '/';
    }

    private function form(string $back, ?string $error = null, int $status = 401): Response
    {
        return response()->view('preprod-gate', ['back' => $this->back($back), 'error' => $error, 'action' => '/'.self::PATH], $status)
            ->header('Cache-Control', 'no-store');
    }
}
