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
 * Wave Business, API Checkout (api.wave.com/v1/checkout/sessions) : le client paie dans l'application Wave.
 */
class WaveGateway implements PaymentGateway
{
    private const BASE = 'https://api.wave.com/v1/';

    public function methods(): array
    {
        return ['wave'];
    }

    public function credentialFields(): array
    {
        return ['api_key' => 'Clé API (Checkout)', 'webhook_secret' => 'Secret du webhook'];
    }

    public function createCheckout(PaymentAccount $account, Payment $payment, array $context): Checkout
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('checkout/sessions', [
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'client_reference' => $payment->idempotency_key,
            'success_url' => $context['return_url'],
            'error_url' => $context['cancel_url'],
        ]), $account);

        return new Checkout((string) $response->json('wave_launch_url'), (string) $response->json('id'), $response->json());
    }

    public function verify(PaymentAccount $account, Payment $payment): GatewayResult
    {
        $session = $this->send(fn (PendingRequest $http) => $http->get('checkout/sessions/'.rawurlencode((string) $payment->provider_reference)), $account)->json();

        $status = match (true) {
            ($session['payment_status'] ?? null) === 'succeeded' => GatewayResult::SUCCEEDED,
            ($session['payment_status'] ?? null) === 'cancelled', ($session['checkout_status'] ?? null) === 'expired' => GatewayResult::FAILED,
            default => GatewayResult::PENDING,
        };

        return new GatewayResult($status, isset($session['amount']) ? (int) $session['amount'] : null, 'wave', null,
            $status === GatewayResult::FAILED ? 'Paiement Wave annulé ou expiré' : null, $session);
    }

    /**
     * En-tête Wave-Signature : « t=<horodatage>,v1=<HMAC-SHA256(secret, horodatage + corps)> ».
     */
    public function parseWebhook(PaymentAccount $account, Request $request): ?array
    {
        $header = (string) $request->header('Wave-Signature');
        $body = $request->getContent();
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[$key][] = $value;
        }
        $timestamp = $parts['t'][0] ?? null;
        $secret = $account->credential('webhook_secret');
        if (! $timestamp || ! $secret || abs(time() - (int) $timestamp) > 300) {
            return null;
        }
        $expected = hash_hmac('sha256', $timestamp.$body, $secret);
        if (! collect($parts['v1'] ?? [])->contains(fn ($s) => is_string($s) && hash_equals($expected, $s))) {
            return null;
        }

        $event = json_decode($body, true) ?? [];

        return [
            'event_id' => (string) ($event['id'] ?? sha1($body)),
            'payment_key' => $event['data']['client_reference'] ?? null,
            'provider_reference' => $event['data']['id'] ?? null,
        ];
    }

    /**
     * Wave rembourse la session entière.
     */
    public function refund(PaymentAccount $account, Payment $payment, int $amount): bool
    {
        if ($amount !== $payment->amount) {
            return false;
        }
        $this->send(fn (PendingRequest $http) => $http->post('checkout/sessions/'.rawurlencode((string) $payment->provider_reference).'/refund'), $account);

        return true;
    }

    private function send(callable $call, PaymentAccount $account): Response
    {
        try {
            $response = $call(Http::baseUrl(self::BASE)->withToken((string) $account->credential('api_key'))->acceptJson()->asJson()->timeout(15));
        } catch (ConnectionException) {
            throw new GatewayException('Wave ne répond pas.');
        }
        if ($response->failed()) {
            throw new GatewayException('Wave : '.($response->json('message') ?? 'erreur '.$response->status()));
        }

        return $response;
    }
}
