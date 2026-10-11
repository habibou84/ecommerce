<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\WhatsAppCloud;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Notifications de Meta pour le numéro WhatsApp d'une boutique (api.jibiat-marchand.com/webhooks/whatsapp/{uuid}) :
 * vérification de l'adresse (GET), puis réponses des clients et statuts des messages (POST signé).
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, string $uuid): Response
    {
        $account = WhatsappAccount::withoutGlobalScopes()->where('webhook_uuid', $uuid)->firstOrFail();
        $ok = $request->query('hub_mode') === 'subscribe' && hash_equals($account->verify_token, (string) $request->query('hub_verify_token'));

        return response($ok ? (string) $request->query('hub_challenge') : 'Refusé.', $ok ? 200 : 403);
    }

    public function handle(Request $request, string $uuid, WhatsAppCloud $cloud, CustomerMessenger $messenger, Tenancy $tenancy): Response
    {
        $account = WhatsappAccount::withoutGlobalScopes()->with('store')->where('webhook_uuid', $uuid)->firstOrFail();
        if (! $cloud->verifySignature($account, $request)) {
            return response('Signature invalide.', 401);
        }

        $tenancy->setStore($account->store);
        $messenger->handleInbound($account, $request->json()->all());

        return response('OK');
    }
}
