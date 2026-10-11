<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Services\Payments\GatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayDunya (facture de paiement « checkout-invoice ») : mobile money et cartes. Remboursement non disponible par l'API.
 */
class PayDunyaGateway implements PaymentGateway
{
    public function methods(): array
    {
        return ['orange_money', 'mtn_momo', 'moov_money', 'wave', 'card'];
    }

    public function credentialFields(): array
    {
        return ['master_key' => 'Clé principale (Master Key)', 'private_key' => 'Clé privée', 'token' => 'Token'];
    }

    public function createCheckout(PaymentAccount $account, Payment $payment, array $context): Checkout
    {
        $data = $this->send($account, fn (PendingRequest $http) => $http->post('checkout-invoice/create', [
            'invoice' => ['total_amount' => $payment->amount, 'description' => $context['description']],
            'store' => ['name' => $context['store_name']],
            'actions' => ['callback_url' => $context['notify_url'], 'return_url' => $context['return_url'], 'cancel_url' => $context['cancel_url']],
            'custom_data' => ['payment_key' => $payment->idempotency_key],
        ]))->json();

        if (($data['response_code'] ?? null) !== '00') {
            throw new GatewayException('PayDunya : '.($data['response_text'] ?? 'paiement refusé'));
        }

        return new Checkout((string) $data['response_text'], (string) $data['token'], $data);
    }

    public function verify(PaymentAccount $account, Payment $payment): GatewayResult
    {
        $data = $this->send($account, fn (PendingRequest $http) => $http->get('checkout-invoice/confirm/'.rawurlencode((string) $payment->provider_reference)))->json();
        $status = $data['status'] ?? null;

        return new GatewayResult(
            match ($status) {
                'completed' => GatewayResult::SUCCEEDED,
                'cancelled', 'failed' => GatewayResult::FAILED,
                default => GatewayResult::PENDING,
            },
            isset($data['invoice']['total_amount']) ? (int) $data['invoice']['total_amount'] : null,
            null,
            null,
            in_array($status, ['cancelled', 'failed'], true) ? 'Paiement annulé' : null,
            $data,
        );
    }

    /**
     * Notification (IPN) : champ data[hash] = SHA-512 de la clé principale.
     */
    public function parseWebhook(PaymentAccount $account, Request $request): ?array
    {
        $data = $request->input('data', []);
        $master = $account->credential('master_key');
        if (! is_array($data) || ! $master || ! hash_equals(hash('sha512', $master), (string) ($data['hash'] ?? ''))) {
            return null;
        }

        return [
            'event_id' => sha1(($data['invoice']['token'] ?? '').'|'.($data['status'] ?? '')),
            'payment_key' => $data['custom_data']['payment_key'] ?? null,
            'provider_reference' => $data['invoice']['token'] ?? null,
        ];
    }

    public function refund(PaymentAccount $account, Payment $payment, int $amount): bool
    {
        return false;
    }

    private function send(PaymentAccount $account, callable $call): Response
    {
        $base = $account->mode === 'test' ? 'https://app.paydunya.com/sandbox-api/v1/' : 'https://app.paydunya.com/api/v1/';
        $http = Http::baseUrl($base)->acceptJson()->asJson()->timeout(20)->withHeaders([
            'PAYDUNYA-MASTER-KEY' => (string) $account->credential('master_key'),
            'PAYDUNYA-PRIVATE-KEY' => (string) $account->credential('private_key'),
            'PAYDUNYA-TOKEN' => (string) $account->credential('token'),
        ]);

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw new GatewayException('PayDunya ne répond pas.');
        }
        if ($response->serverError()) {
            throw new GatewayException('PayDunya est indisponible.');
        }

        return $response;
    }
}
