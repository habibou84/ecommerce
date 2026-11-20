<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile : vérifie qu'une personne, pas un robot, passe la commande sur la boutique en ligne
 * (fausses commandes avec les numéros de tiers, qui font envoyer des WhatsApp payants et réservent le stock).
 *
 * Allumé seulement quand TURNSTILE_SITE_KEY et TURNSTILE_SECRET_KEY sont dans le .env ; sinon rien ne change.
 * Cloudflare injoignable : la commande passe quand même (les limites par adresse et par numéro restent).
 */
class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    public function siteKey(): ?string
    {
        return $this->enabled() ? (string) config('services.turnstile.site_key') : null;
    }

    public function passes(mixed $token, ?string $ip): bool
    {
        if (! $this->enabled()) {
            return true;
        }
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (Throwable $e) {
            Log::warning('Turnstile injoignable : commande acceptée sans vérification', ['error' => $e->getMessage()]);

            return true;
        }

        if ($response->serverError()) {
            Log::warning('Turnstile en erreur : commande acceptée sans vérification', ['status' => $response->status()]);

            return true;
        }

        return $response->json('success') === true;
    }
}
