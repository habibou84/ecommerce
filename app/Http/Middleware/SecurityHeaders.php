<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité sur toutes les réponses :
 *  - pas d'affichage dans le cadre d'un autre site (piège à clics sur la gestion) ;
 *  - type des fichiers respecté (pas de « devinette » du navigateur) ;
 *  - adresse précédente réduite au domaine vers les autres sites (les jetons de commande ne fuient pas) ;
 *  - HTTPS imposé au navigateur une fois venu en HTTPS.
 * Seule exception au cadre : l'écran du live (/ecran), montré en aperçu dans la gestion des boutiques de la plateforme
 * (sous-domaines), qui ne contient rien à cliquer de sensible.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $ancestors = "'self'";
        if ($request->routeIs('live-screen')) {
            $domain = (string) config('platform.domain');
            $ancestors .= " https://*.{$domain}".(app()->isProduction() ? '' : " http://*.{$domain}:*");
        } else {
            $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        }
        $headers->set('Content-Security-Policy', "frame-ancestors {$ancestors}; object-src 'none'; base-uri 'self'", false);
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        return $response;
    }
}
