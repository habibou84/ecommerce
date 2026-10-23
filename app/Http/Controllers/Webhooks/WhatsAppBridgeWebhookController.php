<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use App\Models\WhatsappLink;
use App\Services\WhatsApp\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Événements du service whatsapp-bridge (WhatsApp relié par QR code), signés avec WHATSAPP_BRIDGE_TOKEN : état de la
 * session (QR code scanné, téléphone déconnecté), messages des clients, messages écrits depuis le téléphone,
 * réponses envoyées, remises, lues ou en échec.
 */
class WhatsAppBridgeWebhookController extends Controller
{
    public function __construct(private readonly Inbox $inbox) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('services.whatsapp_bridge.token');
        $expected = hash_hmac('sha256', $request->getContent(), $token);
        abort_unless($token !== '' && hash_equals($expected, (string) $request->header('X-Bridge-Signature')), 403, 'Signature invalide.');

        $link = preg_match('/^store-(\d+)$/', (string) $request->input('session'), $m)
            ? WhatsappLink::withoutGlobalScopes()->where('store_id', (int) $m[1])->first() : null;
        if ($link === null) {
            return response()->json(['received' => false]);
        }

        match ((string) $request->input('type')) {
            'status' => $this->status($link, $request),
            'message' => $this->inbox->receiveLinked($link, $request->only(['id', 'from', 'name', 'kind', 'text', 'lat', 'lng', 'at'])),
            'outgoing' => $this->inbox->fromPhone($link, (string) $request->input('to'), (string) $request->input('text'), (string) $request->input('id')),
            'sent', 'delivered', 'read' => $this->advance($link, (string) $request->input('id'), (string) $request->input('type')),
            'failed' => $this->advance($link, (string) $request->input('id'), 'failed', (string) $request->input('error')),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    private function status(WhatsappLink $link, Request $request): void
    {
        match ((string) $request->input('status')) {
            'connected' => $link->forceFill([
                'status' => 'connected',
                'phone' => $request->input('phone') ?: $link->phone,
                'name' => $request->input('name') ?: $link->name,
                'connected_at' => now(),
                'last_error' => null,
            ])->save(),
            'logged_out' => $link->forceFill(['status' => 'disconnected', 'last_error' => $request->input('error') ?: 'Téléphone déconnecté : scannez un nouveau QR code.'])->save(),
            'disconnected' => $link->forceFill(['last_error' => $request->input('error')])->save(),
            default => null,
        };
    }

    /** Réponse envoyée depuis la boîte Messages : remise, lue ou en échec (numéro sans WhatsApp, téléphone déconnecté) */
    private function advance(WhatsappLink $link, string $id, string $status, ?string $error = null): void
    {
        if ($id === '') {
            return;
        }
        $message = ConversationMessage::forStore($link->store_id)->where('provider_message_id', $id)->where('direction', 'out')->first();
        // Pas de retour en arrière : « remis » arrivé après « lu » ne change rien
        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        if ($message === null || ($rank[$message->status] ?? 0) >= $rank[$status]) {
            return;
        }
        $message->forceFill(['status' => $status, 'error' => $error ? 'WhatsApp : '.$error : null])->save();
    }
}
