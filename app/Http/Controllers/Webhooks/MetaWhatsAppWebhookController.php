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
use Illuminate\Support\Facades\Log;

/**
 * Notifications de Meta pour l'application de la plateforme (api.jibiat-marchand.com/webhooks/whatsapp) : les
 * numéros connectés en un clic, et ceux connectés à la main avec un compte WhatsApp de l'application de la plateforme
 * (numéro de test de Meta…). Chaque entrée porte l'identifiant du compte WhatsApp (WABA) de la boutique.
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
            $account = $this->account((string) ($entry['id'] ?? ''));
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

    /**
     * Boutique destinataire : le compte connecté en un clic ; à défaut, un compte connecté à la main, s'il est le seul
     * à déclarer ce compte WhatsApp (sinon personne : les messages ne vont pas à une boutique au hasard).
     */
    private function account(string $wabaId): ?WhatsappAccount
    {
        if ($wabaId === '') {
            return null;
        }
        $accounts = WhatsappAccount::withoutGlobalScopes()->with('store')->where('waba_id', $wabaId)->get();
        $embedded = $accounts->firstWhere('connection', 'embedded');
        if ($embedded !== null) {
            return $embedded;
        }
        if ($accounts->count() > 1) {
            Log::warning('Webhook WhatsApp : plusieurs boutiques déclarent ce compte WhatsApp, message ignoré', ['waba_id' => $wabaId]);

            return null;
        }

        return $accounts->first();
    }
}
