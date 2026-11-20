<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appels de Jibiat (serveur à serveur) : signature WebhookSignature avec un secret partagé, horodatage de moins de
 * 5 minutes. Cartes Membre Premium : JIBIAT_PREMIUM_SECRET (par défaut) ; décisions de la connexion directe
 * (« VerifyPartnerSignature:connect ») : CONNECT_JIBIAT_MARCHAND_SECRET. Contenu signé : le corps brut pour POST, le chemin
 * avec sa requête pour GET et DELETE. Sans secret réglé, tout est refusé.
 */
class VerifyPartnerSignature
{
    public function handle(Request $request, Closure $next, string $secret = 'premium'): Response
    {
        $secret = (string) config("services.jibiat.{$secret}_secret");
        $content = $request->isMethod('POST') ? $request->getContent() : $request->getRequestUri();
        $timestamp = $request->header(Contract::PARTNER_TIMESTAMP_HEADER);
        $signature = $request->header(Contract::PARTNER_SIGNATURE_HEADER);

        if ($secret === '' || ! WebhookSignature::verify($secret, is_string($timestamp) ? $timestamp : null, $content, is_string($signature) ? $signature : null)) {
            return response()->json(['message' => 'Signature absente ou invalide.'], 401);
        }

        return $next($request);
    }
}
