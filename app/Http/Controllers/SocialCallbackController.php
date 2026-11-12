<?php

namespace App\Http\Controllers;

use App\Services\Social\SocialConnections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Retour des fenêtres de connexion Facebook / TikTok (adresse déclarée chez eux, sur le domaine de la plateforme) :
 * on revient à la gestion de la boutique. Ouverte par-dessus l'application installée : la page dit de retourner dans
 * l'application, qui a reçu le résultat.
 */
class SocialCallbackController extends Controller
{
    public function __invoke(Request $request, string $provider, SocialConnections $connections): RedirectResponse|Response
    {
        $back = $connections->callback($provider, (string) $request->query('state'), $request->query('code'),
            $request->query('error_description') ?? $request->query('error_reason') ?? $request->query('error'));

        return $back === null ? response()->view('platform.facebook-done', ['app' => $connections->relayApp]) : redirect()->away($back);
    }
}
