<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Écritures de la boutique (panier, commande) : appels JSON de nos propres pages seulement. Un formulaire d'un
 * autre site ne peut pas ajouter cet en-tête ; le cookie du panier (SameSite=Lax) ne part pas non plus.
 * Remplace la protection CSRF, la boutique n'ouvrant pas de session pour ses visiteurs.
 */
class RequireAjax
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->header('X-Requested-With') === 'XMLHttpRequest', 419, 'Requête refusée.');

        return $next($request);
    }
}
