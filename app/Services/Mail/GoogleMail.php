<?php

namespace App\Services\Mail;

use App\Models\PlatformSetting;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envoi des e-mails de la plateforme par un compte Gmail connecté avec Google (« Se connecter avec Google »), comme
 * Google le recommande, sans mot de passe d'application : l'administrateur autorise une seule fois l'envoi d'e-mails
 * (autorisation « gmail.send », rien d'autre : la plateforme ne lit pas la boîte), puis les e-mails partent par
 * l'API Gmail.
 *
 * L'application Google (identifiant et code secret du client OAuth, créés dans Google Cloud) est saisie dans la
 * console ou posée sur le serveur (GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET). Secret et jeton sont chiffrés en base.
 */
class GoogleMail
{
    public const SCOPES = 'openid email https://www.googleapis.com/auth/gmail.send';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public function clientId(): ?string
    {
        return $this->setting('client_id') ?? (config('services.google.client_id') ?: null);
    }

    private function clientSecret(): ?string
    {
        $secret = $this->setting('client_secret');

        return $secret !== null ? Crypt::decryptString($secret) : (config('services.google.client_secret') ?: null);
    }

    /** L'application Google est réglée : on peut proposer « Se connecter avec Google » */
    public function configured(): bool
    {
        return $this->clientId() !== null && $this->clientSecret() !== null;
    }

    /** Un compte Gmail est connecté : les e-mails partent par lui */
    public function connected(): bool
    {
        return $this->setting('refresh_token') !== null;
    }

    public function email(): ?string
    {
        return $this->setting('email');
    }

    /** Adresse de retour à déclarer dans Google Cloud (« URI de redirection autorisés ») */
    public function redirectUri(): string
    {
        return Tenancy::consoleUrl('/console/gmail/retour');
    }

    public function saveClient(?string $clientId, ?string $clientSecret): void
    {
        if ($clientId !== null) {
            PlatformSetting::put('gmail.client_id', trim($clientId) ?: null);
        }
        if (filled($clientSecret)) {
            PlatformSetting::put('gmail.client_secret', Crypt::encryptString(trim($clientSecret)));
        }
    }

    public function authUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            // Jeton de longue durée, redemandé à chaque connexion pour être sûr de l'obtenir
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ]);
    }

    /**
     * Retour de Google avec le code d'autorisation : garde le jeton de longue durée et l'adresse du compte.
     */
    public function connect(string $code): string
    {
        $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        if (! $response->successful() || blank($response->json('refresh_token'))) {
            throw new RuntimeException('Google a refusé la connexion : '.($response->json('error_description') ?? $response->json('error') ?? 'réponse inattendue').'.');
        }
        if (! str_contains((string) $response->json('scope'), 'gmail.send')) {
            throw new RuntimeException('L\'autorisation d\'envoyer des e-mails n\'a pas été cochée. Recommencez et cochez « Envoyer des e-mails en votre nom ».');
        }
        // Adresse du compte : jeton d'identité reçu directement de Google (connexion chiffrée)
        $email = $this->idTokenEmail((string) $response->json('id_token')) ?? throw new RuntimeException('Adresse Gmail introuvable dans la réponse de Google.');

        PlatformSetting::put('gmail.refresh_token', Crypt::encryptString((string) $response->json('refresh_token')));
        PlatformSetting::put('gmail.email', $email);
        Cache::put($this->accessKey(), (string) $response->json('access_token'), now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 120)));

        return $email;
    }

    /**
     * Déconnecte le compte : l'autorisation est retirée chez Google (au mieux) puis oubliée ici.
     */
    public function disconnect(): void
    {
        if (($token = $this->setting('refresh_token')) !== null) {
            rescue(fn () => Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => Crypt::decryptString($token)]), report: false);
        }
        PlatformSetting::whereIn('key', ['gmail.refresh_token', 'gmail.email'])->delete();
        Cache::forget($this->accessKey());
    }

    /**
     * Envoie un e-mail déjà composé (format MIME complet).
     */
    public function send(string $mime): void
    {
        $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
        $response = Http::withToken($this->accessToken())->timeout(20)->post(self::SEND_URL, ['raw' => $raw]);
        if ($response->status() === 401) {
            // Jeton d'accès refusé : un nouveau, une seule fois
            Cache::forget($this->accessKey());
            $response = Http::withToken($this->accessToken())->timeout(20)->post(self::SEND_URL, ['raw' => $raw]);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Gmail a refusé l\'envoi : '.($response->json('error.message') ?? 'erreur '.$response->status()).'.');
        }
    }

    private function accessToken(): string
    {
        return Cache::remember($this->accessKey(), now()->addMinutes(50), function () {
            $refresh = $this->setting('refresh_token') ?? throw new RuntimeException('Aucun compte Gmail connecté.');
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => Crypt::decryptString($refresh),
                'grant_type' => 'refresh_token',
            ]);
            if (! $response->successful()) {
                // invalid_grant : autorisation retirée dans le compte Google, ou application restée « en test » (7 jours)
                throw new RuntimeException($response->json('error') === 'invalid_grant'
                    ? 'La connexion à Gmail a expiré ou a été retirée : reconnectez Gmail dans la console.'
                    : 'Google a refusé de renouveler l\'accès : '.($response->json('error_description') ?? $response->json('error') ?? 'erreur '.$response->status()).'.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function idTokenEmail(string $idToken): ?string
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($payload) && ($payload['email_verified'] ?? false) && filter_var($payload['email'] ?? null, FILTER_VALIDATE_EMAIL)
            ? $payload['email'] : null;
    }

    private function setting(string $key): ?string
    {
        return PlatformSetting::find('gmail.'.$key)?->value;
    }

    private function accessKey(): string
    {
        return 'gmail-access-token';
    }
}
