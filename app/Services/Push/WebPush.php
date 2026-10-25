<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Envoi de notifications Web Push (RFC 8030), sans dépendance externe :
 * - chiffrement du contenu « aes128gcm » (RFC 8188 et 8291) : ECDH P-256, HKDF-SHA256, AES-128-GCM ;
 * - authentification du serveur par VAPID (RFC 8292) : jeton JWT signé en ES256.
 * Les clés VAPID se génèrent avec « php artisan webpush:vapid » (la mise à jour du serveur les crée si besoin).
 * Même code que Jibiat (livraison).
 */
class WebPush
{
    private const RECORD_SIZE = 4096;

    public static function enabled(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    /**
     * @return array{public_key: string, private_key: string} clés au format base64url (brut, P-256)
     */
    public static function generateVapidKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            'public_key' => self::b64(self::uncompressed($ec['x'], $ec['y'])),
            'private_key' => self::b64(str_pad($ec['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    /**
     * Envoie une notification à un appareil. Retourne false si l'abonnement n'existe plus (supprimé).
     *
     * @param  array<string, mixed>  $payload  { title, body, url, tag }
     */
    public function send(PushSubscription $subscription, array $payload, int $ttl = 3600, string $urgency = 'high'): bool
    {
        return $this->post($subscription, $payload, $ttl, $urgency) !== 'gone';
    }

    /**
     * Envoie une notification et indique si le service push l'a acceptée.
     *
     * @param  array<string, mixed>  $payload  { title, body, url, tag }
     */
    public function deliver(PushSubscription $subscription, array $payload, int $ttl = 86400, string $urgency = 'normal'): bool
    {
        return $this->post($subscription, $payload, $ttl, $urgency) === 'ok';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return 'ok'|'gone'|'error'
     */
    /** Services de notifications des navigateurs (Chrome, Firefox, Safari, Edge) */
    private const PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    /**
     * Adresse d'abonnement acceptée : HTTPS, chez un service de notifications connu (pas une adresse quelconque que le
     * serveur irait appeler).
     */
    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['port']) || isset($parts['user'])) {
            return false;
        }
        $host = strtolower($parts['host']);

        return collect(self::PUSH_HOSTS)->contains(fn (string $allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed));
    }

    private function post(PushSubscription $subscription, array $payload, int $ttl, string $urgency): string
    {
        try {
            $body = $this->encrypt(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                self::unb64($subscription->p256dh),
                self::unb64($subscription->auth),
            );

            if (! self::allowedEndpoint($subscription->endpoint)) {
                return 'error';
            }
            // Jamais de redirection : l'adresse ne doit mener qu'au service de notifications du navigateur
            $response = Http::timeout(10)->withoutRedirecting()
                ->withHeaders([
                    'Authorization' => $this->vapidHeader($subscription->endpoint),
                    'Content-Encoding' => 'aes128gcm',
                    'TTL' => (string) $ttl,
                    'Urgency' => $urgency,
                ])
                ->withBody($body, 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (Throwable $e) {
            Log::warning('Web Push : envoi impossible', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);

            return 'error';
        }

        // Abonnement expiré ou révoqué par l'utilisateur : on l'oublie
        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return 'gone';
        }

        if ($response->successful()) {
            $subscription->forceFill(['last_used_at' => now()])->saveQuietly();

            return 'ok';
        }

        Log::warning('Web Push : refusé', ['subscription' => $subscription->id, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

        return 'error';
    }

    /**
     * Chiffre le contenu pour le navigateur (une seule fiche « aes128gcm »).
     */
    public function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?string $salt = null, $serverKey = null): string
    {
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new RuntimeException('Clés d\'abonnement Web Push invalides.');
        }

        $serverKey ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($serverKey)['ec'];
        $asPublic = self::uncompressed($ec['x'], $ec['y']);
        $salt ??= random_bytes(16);

        $shared = openssl_pkey_derive(self::publicKeyPem($uaPublic), $serverKey, 32);
        if ($shared === false) {
            throw new RuntimeException('Échange de clés ECDH impossible.');
        }

        // RFC 8291 : clé d'entrée combinant le secret partagé et le secret d'authentification
        $prkKey = hash_hmac('sha256', $shared, $authSecret, true);
        $ikm = substr(hash_hmac('sha256', "WebPush: info\0".$uaPublic.$asPublic."\x01", $prkKey, true), 0, 32);

        // RFC 8188 : clé de chiffrement et nonce
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        // Délimiteur de dernière fiche (0x02), sans remplissage
        $tag = '';
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        return $salt.pack('N', self::RECORD_SIZE).chr(strlen($asPublic)).$asPublic.$cipher.$tag;
    }

    /**
     * En-tête VAPID : « vapid t=<JWT ES256>, k=<clé publique> ».
     */
    public function vapidHeader(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $audience = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64(json_encode([
            'aud' => $audience,
            'exp' => now()->addHours(12)->timestamp,
            'sub' => config('services.webpush.subject') ?: 'mailto:contact@'.parse_url((string) config('app.url'), PHP_URL_HOST),
        ]));

        $publicKey = self::unb64(config('services.webpush.public_key'));
        $privateKey = openssl_pkey_get_private(self::privateKeyPem(self::unb64(config('services.webpush.private_key')), $publicKey));

        if (! openssl_sign("{$header}.{$claims}", $der, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature VAPID impossible : vérifiez VAPID_PRIVATE_KEY.');
        }

        return "vapid t={$header}.{$claims}.".self::b64(self::derToRaw($der)).', k='.config('services.webpush.public_key');
    }

    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }

    private static function uncompressed(string $x, string $y): string
    {
        return "\x04".str_pad($x, 32, "\0", STR_PAD_LEFT).str_pad($y, 32, "\0", STR_PAD_LEFT);
    }

    /**
     * Clé publique P-256 brute (65 octets) au format PEM (SubjectPublicKeyInfo).
     */
    public static function publicKeyPem(string $raw)
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$raw;

        return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END PUBLIC KEY-----');
    }

    /**
     * Clé privée P-256 brute (32 octets) au format PEM (ECPrivateKey, RFC 5915).
     */
    public static function privateKeyPem(string $d, string $publicRaw): string
    {
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$publicRaw;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END EC PRIVATE KEY-----';
    }

    /**
     * Signature ECDSA : DER (openssl) → r||s brut de 64 octets (JWT ES256).
     */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $out = '';

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $value = ltrim(substr($der, $offset + 2, $length), "\0");
            $out .= str_pad($value, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $out;
    }
}
