<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappLink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Service whatsapp-bridge (dossier whatsapp-bridge, sur le serveur) : WhatsApp relié par QR code, une session par
 * boutique (« store-12 ») et une pour le numéro du support Jibiat Marchand (« platform », console). Démarrer la
 * session et lire son QR code, envoyer un message (mis en file, réponse immédiate), se déconnecter. Les événements
 * du service arrivent sur WhatsAppBridgeWebhookController.
 */
class WhatsAppBridge
{
    /** Session du numéro du support (console) */
    public const PLATFORM = 'platform';

    public static function configured(): bool
    {
        return filled(config('services.whatsapp_bridge.token'));
    }

    /**
     * @return array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}
     */
    public function start(WhatsappLink $link): array
    {
        return $this->startSession($link->session());
    }

    /**
     * @return array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}
     */
    public function startSession(string $session): array
    {
        return $this->describe($this->call(fn () => $this->http(30)->post('/sessions/'.$session.'/start')));
    }

    /**
     * Liaison avec le numéro plutôt que le QR code : code à 8 caractères à saisir sur le téléphone.
     *
     * @return array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}
     */
    public function pair(WhatsappLink $link, string $phone): array
    {
        return $this->pairSession($link->session(), $phone);
    }

    /**
     * @return array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}
     */
    public function pairSession(string $session, string $phone): array
    {
        return $this->describe($this->call(fn () => $this->http(30)->post('/sessions/'.$session.'/pair', ['phone' => $phone])));
    }

    /**
     * @return array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}
     */
    public function status(WhatsappLink $link): array
    {
        return $this->statusSession($link->session());
    }

    /**
     * @return array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}
     */
    public function statusSession(string $session): array
    {
        return $this->describe($this->call(fn () => $this->http()->get('/sessions/'.$session)));
    }

    public function logout(WhatsappLink $link): void
    {
        $this->logoutSession($link->session());
    }

    public function logoutSession(string $session): void
    {
        $this->call(fn () => $this->http(30)->post('/sessions/'.$session.'/logout'));
    }

    /**
     * Message mis dans la file du service ; son envoi réel suit (événement « sent » ou « failed »).
     *
     * @return string identifiant donné par le service
     */
    public function send(WhatsappLink $link, string $to, string $text): string
    {
        return $this->sendFrom($link->session(), $to, $text);
    }

    /**
     * @return string identifiant donné par le service
     */
    public function sendFrom(string $session, string $to, string $text): string
    {
        return (string) $this->call(fn () => $this->http()->post('/sessions/'.$session.'/send', ['to' => $to, 'text' => $text]))->json('id');
    }

    private function http(int $timeout = 15): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.whatsapp_bridge.url'), '/'))
            ->withToken((string) config('services.whatsapp_bridge.token'))
            ->acceptJson()
            ->timeout($timeout);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function call(callable $request): Response
    {
        if (! self::configured()) {
            throw new RuntimeException('Connexion par QR code indisponible : le service WhatsApp n\'est pas installé sur le serveur.');
        }
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new RuntimeException('Le service WhatsApp du serveur ne répond pas. Réessayez dans un instant.');
        }
        if ($response->failed()) {
            throw new RuntimeException($response->json('message') ?? 'Erreur du service WhatsApp ('.$response->status().').');
        }

        return $response;
    }

    /**
     * @return array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}
     */
    private function describe(Response $response): array
    {
        return [
            'status' => (string) ($response->json('status') ?? 'logged_out'),
            'qr' => $response->json('qr'),
            'code' => $response->json('code'),
            'phone' => $response->json('phone'),
            'name' => $response->json('name'),
        ];
    }
}
