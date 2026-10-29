<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\WhatsappAccount;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\Inbox;
use App\Services\WhatsApp\PlatformWhatsApp;
use App\Services\WhatsApp\TemplateLibrary;
use App\Services\WhatsApp\WhatsAppCloud;
use App\Services\WhatsApp\WhatsAppMetaApp;
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
        // Jeton de l'application de la plateforme, ou celui créé pour le numéro du support configuré à la main
        $given = (string) $request->query('hub_verify_token');
        $tokens = array_filter([(string) config('services.meta.webhook_verify_token'), (string) app(PlatformWhatsApp::class)->manualVerifyToken()]);
        $ok = $request->query('hub_mode') === 'subscribe' && collect($tokens)->contains(fn (string $t) => hash_equals($t, $given));

        return response($ok ? (string) $request->query('hub_challenge') : 'Refusé.', $ok ? 200 : 403);
    }

    public function handle(Request $request, CustomerMessenger $messenger, TemplateLibrary $templates, Tenancy $tenancy): Response
    {
        // Application de la plateforme (réseaux sociaux, WhatsApp), application WhatsApp partagée, ou numéro du support
        if (! WhatsAppCloud::signatureMatches($request, config('services.meta.app_secret'))
            && ! WhatsAppCloud::signatureMatches($request, WhatsAppMetaApp::secret())
            && ! WhatsAppCloud::signatureMatches($request, app(PlatformWhatsApp::class)->manualAppSecret())) {
            return response('Signature invalide.', 401);
        }
        // Même adresse pour les messages Messenger (page) et Instagram : boîte Messages de la boutique
        if (in_array($request->json('object'), ['page', 'instagram'], true)) {
            $this->social($request, $tenancy);

            return response('OK');
        }

        // Numéro du support Jibiat Marchand : boîte WhatsApp de la console
        $support = app(PlatformWhatsApp::class)->wabaId();
        foreach ($request->json('entry') ?? [] as $entry) {
            if ($support !== null && (string) ($entry['id'] ?? '') === $support) {
                app(PlatformWhatsApp::class)->handle($entry);

                continue;
            }
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

    private function social(Request $request, Tenancy $tenancy): void
    {
        $network = $request->json('object') === 'page' ? 'facebook' : 'instagram';
        foreach ($request->json('entry') ?? [] as $entry) {
            $account = SocialAccount::withoutGlobalScopes()->with('store')->where('network', $network)->where('external_id', (string) ($entry['id'] ?? ''))->first();
            if ($account === null || $account->store === null) {
                continue;
            }
            $tenancy->setStore($account->store);
            foreach ($entry['messaging'] ?? [] as $event) {
                app(Inbox::class)->receiveSocial($account, $event);
            }
        }
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
