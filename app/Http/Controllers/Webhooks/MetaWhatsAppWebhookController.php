<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\TemplateLibrary;
use App\Services\WhatsApp\WhatsAppCloud;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Notifications de Meta pour l'application de la plateforme (api.jibiat-marchand.com/webhooks/whatsapp) : tous les
 * numéros connectés en un clic. Chaque entrée porte l'identifiant du compte WhatsApp (WABA) de la boutique.
 */
class MetaWhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.meta.webhook_verify_token');
        $ok = $expected !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($expected, (string) $request->query('hub_verify_token'));

        return response($ok ? (string) $request->query('hub_challenge') : 'Refusé.', $ok ? 200 : 403);
    }

    public function handle(Request $request, CustomerMessenger $messenger, TemplateLibrary $templates, Tenancy $tenancy): Response
    {
        if (! WhatsAppCloud::signatureMatches($request, config('services.meta.app_secret'))) {
            return response('Signature invalide.', 401);
        }

        foreach ($request->json('entry') ?? [] as $entry) {
            $account = WhatsappAccount::withoutGlobalScopes()->with('store')->where('connection', 'embedded')
                ->where('waba_id', (string) ($entry['id'] ?? ''))->first();
            if ($account === null) {
                continue;
            }
            $tenancy->setStore($account->store);

            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) === 'message_template_status_update') {
                    $templates->updateStatus($account, (string) ($change['value']['message_template_name'] ?? ''), (string) ($change['value']['event'] ?? ''));
                }
            }
            $messenger->handleInbound($account, ['entry' => [$entry]]);
        }

        return response('OK');
    }
}
