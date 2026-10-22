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
 * boutique. Démarrer la session et lire son QR code, envoyer un message (mis en file, réponse immédiate), se
 * déconnecter. Les événements du service arrivent sur WhatsAppBridgeWebhookController.
 */
class WhatsAppBridge
{
    public static function configured(): bool
    {
        return filled(config('services.whatsapp_bridge.token'));
    }

    /**
     * @return array{status: string, qr: ?string, phone: ?string, name: ?string}
     */
    public function start(WhatsappLink $link): array
    {
        return $this->describe($this->call(fn () => $this->http(30)->post('/sessions/'.$link->session().'/start')));
    }

    /**
     * @return array{status: string, qr: ?string, phone: ?string, name: ?string}
     */
    public function status(WhatsappLink $link): array
    {
        return $this->describe($this->call(fn () => $this->http()->get('/sessions/'.$link->session())));
    }

    public function logout(WhatsappLink $link): void
    {
        $this->call(fn () => $this->http(30)->post('/sessions/'.$link->session().'/logout'));
    }

    /**
     * Message mis dans la file du service ; son envoi réel suit (événement « sent » ou « failed »).
     *
     * @return string identifiant donné par le service
     */
    public function send(WhatsappLink $link, string $to, string $text): string
    {
        return (string) $this->call(fn () => $this->http()->post('/sessions/'.$link->session().'/send', ['to' => $to, 'text' => $text]))->json('id');
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
     * @return array{status: string, qr: ?string, phone: ?string, name: ?string}
     */
    private function describe(Response $response): array
    {
        return [
            'status' => (string) ($response->json('status') ?? 'logged_out'),
            'qr' => $response->json('qr'),
            'phone' => $response->json('phone'),
            'name' => $response->json('name'),
        ];
    }
}
