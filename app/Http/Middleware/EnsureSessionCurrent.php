<?php

namespace App\Http\Middleware;

use App\Services\Accounts\Sessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session fermée à distance (administrateur retiré, validation en deux étapes réinitialisée, accès livreur coupé,
 * « fermer mes autres sessions »…) : déconnectée à sa prochaine requête, même quand les sessions sont dans Redis.
 */
class EnsureSessionCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $guard = Auth::guard('web');
            $user = $guard->user();
            if ($user !== null && ! Sessions::current($user, $request->session())) {
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                if ($request->expectsJson() || $request->is('api/*')) {
                    abort(401, 'Votre session a été fermée. Reconnectez-vous.');
                }

                return redirect($request->fullUrl());
            }
        }

        return $next($request);
    }
}
