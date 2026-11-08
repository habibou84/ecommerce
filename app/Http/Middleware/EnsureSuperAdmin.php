<?php

namespace App\Http\Middleware;

use App\Services\Accounts\ConsoleTwoFactor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console de la plateforme : administrateur actif, connecté depuis moins de 12 heures (passé ce délai, la session se
 * ferme et il faut un nouveau code). Tant que la validation en deux étapes n'est pas activée, seules son activation,
 * « moi » et la déconnexion sont ouvertes.
 */
class EnsureSuperAdmin
{
    public const MAX_HOURS = 12;

    private const BEFORE_TWO_FACTOR = ['v1.console.me', 'v1.console.logout', 'v1.console.2fa', 'v1.console.2fa.setup', 'v1.console.2fa.confirm'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $request->hasSession() || $request->session()->get('console_admin') !== $user->id) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }
        $since = (int) $request->session()->get('console_since', 0);
        if ($since < now()->subHours(self::MAX_HOURS)->timestamp) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return response()->json(['message' => 'Session expirée : reconnectez-vous.'], 401);
        }
        if (! $user->is_super_admin || ! $user->isActive()) {
            return response()->json(['message' => 'Accès réservé à l\'équipe Jibiat Marchand.'], 403);
        }
        if (! ConsoleTwoFactor::enabled($user) && ! $request->routeIs(...self::BEFORE_TWO_FACTOR)) {
            return response()->json(['message' => 'Activez d\'abord la validation en deux étapes (Mon compte).', 'two_factor_setup' => true], 403);
        }

        return $next($request);
    }
}
