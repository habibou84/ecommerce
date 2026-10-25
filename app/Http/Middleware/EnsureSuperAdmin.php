<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console de la plateforme : administrateur actif, connecté avec son code (e-mail, WhatsApp ou SMS) depuis moins de
 * 12 heures : passé ce délai, la session se ferme et il faut un nouveau code.
 */
class EnsureSuperAdmin
{
    public const MAX_HOURS = 12;

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

        return $next($request);
    }
}
