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

        // E-mail d'abord ; SMS sinon (« journal » : le serveur n'envoie pas encore de vrais SMS)
        $channel = $this->mail($details) === null ? 'mail' : ($this->text($details) === null ? ($this->settings->smsReady() ? 'sms' : 'journal') : null);
        $raw['alerts'][] = ['at' => now()->toIso8601String(), 'claimed' => $claimed, 'channel' => $channel];
        $invoice->update(['raw' => $raw]);

        return $channel;
    }

    /**
     * Alerte d'essai depuis la console : e-mail et SMS au validateur, avec ce qui a bloqué.
     *
     * @return array{mail: array{ok: bool, detail: string}, sms: array{ok: bool, detail: string}}
     */
    public function test(): array
    {
        $details = ['test' => true, 'console_url' => Tenancy::consoleUrl('/console/paiements')];
        $mail = $this->mail($details);
        $sms = $this->settings->smsReady() ? $this->text($details) : 'Le serveur n\'envoie pas encore de vrais SMS (fournisseur de SMS non configuré).';

        return [
            'mail' => ['ok' => $mail === null, 'detail' => $mail ?? 'E-mail envoyé à '.$this->settings->validatorEmail().'. Regardez aussi dans les spams.'],
            'sms' => ['ok' => $sms === null, 'detail' => $sms ?? 'SMS envoyé au '.$this->settings->validatorPhone().'.'],
        ];
    }

    /**
     * @return string|null null si l'e-mail est parti, sinon la raison
     */
    private function mail(array $details): ?string
    {
        $email = $this->settings->validatorEmail();
        if ($email === null) {
            return 'Aucune adresse e-mail de validateur.';
        }
        $mailer = $this->settings->mailer();
        if ($mailer === null) {
            return 'Aucun serveur d\'e-mail réglé (Envoi des e-mails, plus bas).';
        }
        try {
            Notification::route('mail', $email)->notifyNow(new WavePaymentToValidate($details, $mailer['mailer'], $mailer['from']));

            return null;
        } catch (Throwable $e) {
            Log::warning('E-mail de paiement Wave non envoyé, passage au SMS', ['invoice' => $details['number'] ?? 'essai', 'error' => $e->getMessage()]);

            return 'Le serveur d\'e-mail a refusé l\'envoi : '.mb_substr($e->getMessage(), 0, 200);
        }
    }

    /**
     * @return string|null null si le SMS est parti, sinon la raison
     */
    private function text(array $details): ?string
    {
        $phone = $this->settings->validatorPhone();
        if ($phone === null) {
            return 'Aucun téléphone de validateur.';
        }
        $text = ($details['test'] ?? false)
            ? 'Jibiat Marchand · Essai : les alertes de paiement par SMS fonctionnent.'
            : 'Jibiat Marchand · '.($details['claimed'] ? 'Paiement Wave déclaré payé' : 'Paiement Wave à vérifier')
                ." : {$details['amount']} depuis le ".($details['payer'] ?? '?').". {$details['label']} ({$details['number']}). Confirmer : {$details['validate_url']}";
        try {
            $this->sms->send($phone, $text);

            return null;
        } catch (Throwable $e) {
            // Le paiement reste visible dans la console
            Log::warning('SMS de paiement Wave non envoyé', ['invoice' => $details['number'] ?? 'essai', 'error' => $e->getMessage()]);

            return 'Le SMS n\'est pas parti : '.mb_substr($e->getMessage(), 0, 200);
        }
    }
}
