<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\PaymentAlerts;
use App\Services\Mail\GoogleMail;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Console : réglage du paiement des abonnements (moyen actif, lien et QR code Wave, validateur) et paiements Wave
 * à valider (l'administrateur connecté valide sans code).
 */
class ConsolePaymentsController extends Controller
{
    public function __construct(private readonly Billing $billing, private readonly BillingSettings $settings) {}

    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settings->present()]);
    }

    public function update(Request $request): JsonResponse
    {
        // Lien collé sans « https:// » : complété ; {montant} est le repère du montant, pas une erreur d'adresse
        $link = trim((string) $request->input('wave_link'));
        if ($link !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $link)) {
            $request->merge(['wave_link' => 'https://'.$link]);
        }
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(array_filter($this->settings->available())))],
            'wave_link' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) {
                if (! filter_var(str_replace('{montant}', '1000', (string) $value), FILTER_VALIDATE_URL) || ! str_starts_with((string) $value, 'https://')) {
                    $fail('Le lien Wave doit être une adresse qui commence par https://, par exemple https://pay.wave.com/m/…');
                }
            }],
            'validator_email' => ['nullable', 'email', 'max:190'],
            'validator_phone' => ['nullable', new PhoneRule],
            'validator_pin' => ['nullable', 'string', 'min:4', 'max:40'],
        ], ['provider.in' => 'Ce moyen de paiement n\'a pas ses clés sur le serveur.'], [
            'provider' => 'moyen de paiement', 'wave_link' => 'lien Wave', 'validator_email' => 'e-mail du validateur',
            'validator_phone' => 'téléphone du validateur', 'validator_pin' => 'code de validation',
        ]);
        if (filled($data['validator_phone'] ?? null)) {
            $data['validator_phone'] = PhoneNumber::normalize($data['validator_phone']);
        }
        $this->settings->update($data);
        $this->log($request, 'billing_settings', ['provider' => $data['provider'], 'pin_changed' => filled($data['validator_pin'] ?? null)]);

        return $this->settings();
    }

    /**
     * Autre serveur d'e-mail (SMTP, avancé), utilisé seulement si Gmail n'est pas connecté avec Google. Le mot de passe
     * n'est jamais renvoyé ; un hôte vide retire le réglage.
     */
    public function updateMail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:190'],
            'from_address' => ['nullable', 'email', 'max:190'],
        ], [], ['host' => 'serveur', 'port' => 'port', 'username' => 'identifiant', 'password' => 'mot de passe', 'from_address' => 'adresse d\'envoi']);
        $this->settings->updateMail($data);
        $this->log($request, 'billing_mail_settings', ['host' => $data['host'] ?? null]);

        return $this->settings();
    }

    /**
     * Application Google (client OAuth) qui permet de connecter Gmail : identifiant et code secret (jamais renvoyé).
     */
    public function updateGmailClient(Request $request, GoogleMail $gmail): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-_]+\.apps\.googleusercontent\.com$/'],
            'client_secret' => ['nullable', 'string', 'max:190'],
        ], ['client_id.regex' => 'L\'identifiant du client se termine par .apps.googleusercontent.com.'], ['client_id' => 'ID client', 'client_secret' => 'code secret du client']);
        $gmail->saveClient($data['client_id'], $data['client_secret'] ?? null);
        $this->log($request, 'gmail_client');

        return $this->settings();
    }

    /**
     * « Se connecter avec Google » : adresse de la page Google où l'administrateur autorise l'envoi d'e-mails.
     */
    public function connectGmail(Request $request, GoogleMail $gmail): JsonResponse
    {
        abort_unless($gmail->configured(), 422, 'Réglez d\'abord l\'application Google (ID client et code secret).');
        $state = Str::random(40);
        $request->session()->put('gmail_state', $state);

        return response()->json(['data' => ['url' => $gmail->authUrl($state)]]);
    }

    public function disconnectGmail(Request $request, GoogleMail $gmail): JsonResponse
    {
        $gmail->disconnect();
        $this->log($request, 'gmail_disconnected');

        return $this->settings();
    }

    /**
     * Alerte d'essai au validateur : e-mail et SMS, avec la raison d'un échec.
     */
    public function testAlert(Request $request, PaymentAlerts $alerts): JsonResponse
    {
        $this->log($request, 'billing_alert_test');

        return response()->json(['data' => $alerts->test()]);
    }

    public function uploadQr(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096']], [], ['image' => 'QR code']);
        $this->settings->storeQr($request->file('image'));
        $this->log($request, 'billing_wave_qr');

        return $this->settings();
    }

    public function removeQr(Request $request): JsonResponse
    {
        $this->settings->removeQr();
        $this->log($request, 'billing_wave_qr_removed');

        return $this->settings();
    }

    /**
     * Paiements Wave en attente de vérification, les plus récents d'abord.
     */
    public function pending(): JsonResponse
    {
        $invoices = SubscriptionInvoice::withoutGlobalScopes()->with(['plan', 'store:id,name,slug'])
            ->where('provider', 'wave_link')->where('status', 'pending')->latest('id')->limit(100)->get();

        return response()->json([
            'data' => $invoices->map(fn (SubscriptionInvoice $i) => $this->billing->presentInvoice($i) + [
                'store' => ['id' => $i->store?->id, 'name' => $i->store?->name],
                'payer_phone' => $i->raw['payer_phone'] ?? null,
                'payer_name' => $i->raw['payer_name'] ?? null,
                'label' => $i->raw['description'] ?? $i->number,
                'alerts' => $i->raw['alerts'] ?? [],
            ]),
            'meta' => ['claimed' => $invoices->filter(fn ($i) => isset($i->raw['claimed_at']))->count()],
        ]);
    }

    public function decide(Request $request, int $invoice): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:received,missing']]);
        $model = SubscriptionInvoice::withoutGlobalScopes()->where('provider', 'wave_link')->findOrFail($invoice);
        $model = $this->billing->decide($model, $data['decision'] === 'received');
        AdminAction::create(['admin_id' => $request->user()->id, 'store_id' => $model->store_id, 'action' => 'billing_wave_'.$data['decision'], 'data' => ['invoice' => $model->number], 'ip' => $request->ip()]);

        return response()->json(['data' => ['id' => $model->id, 'status' => $model->status]]);
    }

    private function log(Request $request, string $action, array $data = []): void
    {
        AdminAction::create(['admin_id' => $request->user()->id, 'store_id' => null, 'action' => $action, 'data' => $data ?: null, 'ip' => $request->ip()]);
    }
}
