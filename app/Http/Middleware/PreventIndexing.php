<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hors production (préproduction, essais) : toutes les réponses demandent aux moteurs de recherche de ne pas indexer,
 * pour que les boutiques de test n'apparaissent pas dans Google (voir aussi robots.txt).
 */
class PreventIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! config('platform.indexable')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
