<?php

namespace App\Services\Billing;

use App\Models\SubscriptionInvoice;
use App\Notifications\WavePaymentToValidate;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Jibiat\Socle\Money;
use Jibiat\Socle\Sms\SmsGateway;
use Throwable;

/**
 * Alerte du validateur sur un paiement par lien Wave : e-mail d'abord (gratuit), SMS si l'e-mail ne part pas ou
 * qu'aucune adresse n'est réglée. Le paiement apparaît aussi dans la console (Paiements à valider), quoi qu'il arrive.
 */
class PaymentAlerts
{
    public function __construct(private readonly BillingSettings $settings, private readonly SmsGateway $sms) {}

    /**
     * @return string|null canal utilisé : mail, sms, ou null si rien n'est parti
     */
    public function alert(SubscriptionInvoice $invoice, bool $claimed): ?string
    {
        $invoice->loadMissing('store');
        $raw = $invoice->raw ?? [];
        // Lien signé sur l'adresse de la plateforme, quelle que soit l'adresse de l'espace marchand qui paie
        $validate = Tenancy::platformUrl(URL::temporarySignedRoute('billing.validate', now()->addDays(7), ['invoice' => $invoice->id], absolute: false));
        $details = [
            'amount' => Money::format($invoice->amount),
            'payer' => $raw['payer_phone'] ?? null,
            'store' => $invoice->store?->name,
            'label' => $raw['description'] ?? $invoice->number,
            'number' => $invoice->number,
            'claimed' => $claimed,
            'validate_url' => $validate,
            'console_url' => Tenancy::consoleUrl('/console/paiements'),
        ];

        $channel = $this->mail($details) ? 'mail' : ($this->text($details) ? 'sms' : null);
        $raw['alerts'][] = ['at' => now()->toIso8601String(), 'claimed' => $claimed, 'channel' => $channel];
        $invoice->update(['raw' => $raw]);

        return $channel;
    }

    private function mail(array $details): bool
    {
        $email = $this->settings->validatorEmail();
        if ($email === null || ! $this->settings->mailReady()) {
            return false;
        }
        try {
            Notification::route('mail', $email)->notifyNow(new WavePaymentToValidate($details));

            return true;
        } catch (Throwable $e) {
            Log::warning('E-mail de paiement Wave non envoyé, passage au SMS', ['invoice' => $details['number'], 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function text(array $details): bool
    {
        $phone = $this->settings->validatorPhone();
        if ($phone === null) {
            return false;
        }
        try {
            $this->sms->send($phone, 'Jibiat Marchand · '.($details['claimed'] ? 'Paiement Wave déclaré payé' : 'Paiement Wave à vérifier')
                ." : {$details['amount']} depuis le ".($details['payer'] ?? '?').". {$details['label']} ({$details['number']}). Confirmer : {$details['validate_url']}");

            return true;
        } catch (Throwable $e) {
            // Le paiement reste visible dans la console
            Log::warning('SMS de paiement Wave non envoyé', ['invoice' => $details['number'], 'error' => $e->getMessage()]);

            return false;
        }
    }
}
