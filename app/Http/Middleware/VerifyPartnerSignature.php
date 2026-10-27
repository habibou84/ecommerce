<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appels de Jibiat (serveur à serveur) pour les cartes Membre Premium : signature WebhookSignature avec le secret
 * partagé JIBIAT_PREMIUM_SECRET, horodatage de moins de 5 minutes. Contenu signé : le corps brut pour POST, le chemin
 * avec sa requête pour GET et DELETE. Sans secret réglé, tout est refusé.
 */
class VerifyPartnerSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.jibiat.premium_secret');
        $content = $request->isMethod('POST') ? $request->getContent() : $request->getRequestUri();
        $timestamp = $request->header(Contract::PARTNER_TIMESTAMP_HEADER);
        $signature = $request->header(Contract::PARTNER_SIGNATURE_HEADER);

        if ($secret === '' || ! WebhookSignature::verify($secret, is_string($timestamp) ? $timestamp : null, $content, is_string($signature) ? $signature : null)) {
            return response()->json(['message' => 'Signature absente ou invalide.'], 401);
        }

        return $next($request);
    }
}
