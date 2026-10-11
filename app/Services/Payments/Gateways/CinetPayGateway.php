<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Services\Payments\GatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * CinetPay (API Checkout v2) : Orange Money, MTN, Moov, Wave et cartes. Remboursement non disponible par l'API.
 */
class CinetPayGateway implements PaymentGateway
{
    private const BASE = 'https://api-checkout.cinetpay.com/v2/';

    // Champs de la notification signés par x-token (HMAC-SHA256 avec la clé secrète), dans cet ordre
    private const SIGNED_FIELDS = [
        'cpm_site_id', 'cpm_trans_id', 'cpm_trans_date', 'cpm_amount', 'cpm_currency', 'signature', 'payment_method', 'cel_phone_num',
        'cpm_phone_prefixe', 'cpm_language', 'cpm_version', 'cpm_payment_config', 'cpm_page_action', 'cpm_custom', 'cpm_designation',
        'cpm_error_message',
    ];

    public function methods(): array
    {
        return ['orange_money', 'mtn_momo', 'moov_money', 'wave', 'card'];
    }

    public function credentialFields(): array
    {
        return ['apikey' => 'Clé API (apikey)', 'site_id' => 'Identifiant du site (site_id)', 'secret_key' => 'Clé secrète'];
    }

    public function createCheckout(PaymentAccount $account, Payment $payment, array $context): Checkout
    {
        if ($payment->amount % 5 !== 0) {
            throw new GatewayException('CinetPay n\'accepte que des montants multiples de 5 F.');
        }

        $data = $this->post('payment', [
            'apikey' => $account->credential('apikey'),
            'site_id' => $account->credential('site_id'),
            'transaction_id' => $this->transactionId($payment),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'description' => mb_substr(preg_replace('/[^\pL\pN #-]/u', ' ', $context['description']), 0, 100),
            'notify_url' => $context['notify_url'],
            'return_url' => $context['return_url'],
            'channels' => match ($payment->method->value) {
                'card' => 'CREDIT_CARD',
                'wave' => 'WALLET',
                default => 'MOBILE_MONEY',
            },
            'customer_name' => $context['customer_name'] ?? 'Client',
            'customer_surname' => $context['customer_name'] ?? 'Client',
            'customer_phone_number' => $context['customer_phone'],
            'lang' => 'fr',
        ]);

        if (($data['code'] ?? null) !== '201') {
            throw new GatewayException('CinetPay : '.($data['description'] ?? $data['message'] ?? 'paiement refusé'));
        }

        return new Checkout((string) $data['data']['payment_url'], $data['data']['payment_token'] ?? null, $data);
    }

    public function verify(PaymentAccount $account, Payment $payment): GatewayResult
    {
        $data = $this->post('payment/check', [
            'apikey' => $account->credential('apikey'),
            'site_id' => $account->credential('site_id'),
            'transaction_id' => $this->transactionId($payment),
        ]);
        $status = $data['data']['status'] ?? null;

        return new GatewayResult(
            match ($status) {
                'ACCEPTED' => GatewayResult::SUCCEEDED,
                'REFUSED', 'CANCELED' => GatewayResult::FAILED,
                default => GatewayResult::PENDING,
            },
            isset($data['data']['amount']) ? (int) $data['data']['amount'] : null,
            $this->method($data['data']['payment_method'] ?? null),
            null,
            in_array($status, ['REFUSED', 'CANCELED'], true) ? ($data['message'] ?? 'Paiement refusé') : null,
            $data,
        );
    }

    public function parseWebhook(PaymentAccount $account, Request $request): ?array
    {
        $secret = $account->credential('secret_key');
        $token = (string) $request->header('x-token');
        $signed = implode('', array_map(fn ($f) => (string) $request->input($f, ''), self::SIGNED_FIELDS));

        if (! $secret || $token === '' || ! hash_equals(hash_hmac('sha256', $signed, $secret), $token)) {
            return null;
        }

        return [
            'event_id' => sha1($request->input('cpm_trans_id').'|'.$request->input('cpm_trans_date').'|'.$request->input('cpm_result')),
            'payment_key' => $request->input('cpm_trans_id'),
            'provider_reference' => null,
        ];
    }

    public function refund(PaymentAccount $account, Payment $payment, int $amount): bool
    {
        return false;
    }

    /**
     * Notre identifiant de transaction : la clé d'idempotence du paiement, sans tirets.
     */
    private function transactionId(Payment $payment): string
    {
        return str_replace('-', '', $payment->idempotency_key);
    }

    private function method(?string $operator): ?string
    {
        return match (true) {
            $operator === null => null,
            str_contains($operator, 'OM') => 'orange_money',
            str_contains($operator, 'MOMO') || str_contains($operator, 'MTN') => 'mtn_momo',
            str_contains($operator, 'FLOOZ') || str_contains($operator, 'MOOV') => 'moov_money',
            str_contains($operator, 'WAVE') => 'wave',
            default => 'card',
        };
    }

    private function post(string $path, array $body): array
    {
        try {
            $response = Http::acceptJson()->asJson()->timeout(20)->post(self::BASE.$path, $body);
        } catch (ConnectionException) {
            throw new GatewayException('CinetPay ne répond pas.');
        }
        if ($response->serverError()) {
            throw new GatewayException('CinetPay est indisponible.');
        }

        return $response->json() ?? [];
    }
}
