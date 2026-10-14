<?php

namespace App\Services\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envoi des notifications push par Firebase Cloud Messaging (API HTTP v1), avec la clé du compte de service du
 * projet Firebase (FIREBASE_CREDENTIALS : chemin du fichier JSON).
 */
class FirebaseMessaging
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public static function enabled(): bool
    {
        return self::credentials() !== null;
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri?: string}|null
     */
    public static function credentials(): ?array
    {
        $path = config('services.firebase.credentials');
        if (! $path) {
            return null;
        }
        $path = str_starts_with($path, '/') ? $path : base_path($path);
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) && isset($data['project_id'], $data['client_email'], $data['private_key']) ? $data : null;
    }

    /**
     * @param  array<string, string>  $data
     * @return bool false si l'adresse de l'appareil n'est plus valable (application désinstallée…)
     */
    public function send(string $token, string $title, string $body, array $data = []): bool
    {
        $credentials = self::credentials() ?? throw new RuntimeException('Firebase n\'est pas configuré.');

        try {
            $response = Http::withToken($this->accessToken($credentials))->acceptJson()->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", ['message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $data,
                    'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'jibiat', 'sound' => 'default']],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ]]);
        } catch (ConnectionException) {
            throw new RuntimeException('Firebase ne répond pas.');
        }

        if ($response->successful()) {
            return true;
        }
        // Appareil inconnu de Firebase : l'application a été désinstallée ou l'adresse a changé
        $code = (string) ($response->json('error.details.0.errorCode') ?? '');
        if ($code === 'UNREGISTERED' || $response->status() === 404) {
            return false;
        }

        throw new RuntimeException('Firebase : '.($response->json('error.message') ?? 'erreur '.$response->status()));
    }

    /**
     * Jeton d'accès OAuth du compte de service (JWT signé RS256), gardé 50 minutes.
     *
     * @param  array{client_email: string, private_key: string, token_uri?: string}  $credentials
     */
    private function accessToken(array $credentials): string
    {
        return Cache::remember('firebase-token:'.md5($credentials['client_email']), now()->addMinutes(50), function () use ($credentials) {
            $uri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $segments = [
                $this->base64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->base64((string) json_encode(['iss' => $credentials['client_email'], 'scope' => self::SCOPE, 'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600])),
            ];
            if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Clé du compte de service Firebase illisible.');
            }
            $segments[] = $this->base64($signature);

            $response = Http::asForm()->timeout(10)->post($uri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ]);
            if ($response->failed() || ! $response->json('access_token')) {
                throw new RuntimeException('Firebase : accès refusé ('.($response->json('error_description') ?? $response->status()).').');
            }

            return (string) $response->json('access_token');
        });
    }

    private function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
