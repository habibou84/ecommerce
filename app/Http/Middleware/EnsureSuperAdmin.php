<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console de la plateforme : administrateur actif, connecté avec son code SMS.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $request->hasSession() || $request->session()->get('console_admin') !== $user->id) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }
        if (! $user->is_super_admin || ! $user->isActive()) {
            return response()->json(['message' => 'Accès réservé à l\'équipe Jibiat Marchand.'], 403);
        }

        return $next($request);
    }
}
