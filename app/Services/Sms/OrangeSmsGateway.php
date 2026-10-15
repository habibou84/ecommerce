<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jibiat\Socle\Sms\SmsException;
use Jibiat\Socle\Sms\SmsGateway;

/**
 * SMS par l'API d'Orange (developer.orange.com, offre « SMS Côte d'Ivoire ») : jeton OAuth du client de l'application,
 * valable une heure, gardé en cache et renouvelé au besoin ; envoi sous le nom d'expéditeur validé par Orange.
 */
class OrangeSmsGateway implements SmsGateway
{
    public const TOKEN_URL = 'https://api.orange.com/oauth/v3/token';

    public const API = 'https://api.orange.com/smsmessaging/v1';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $senderAddress,
        private readonly ?string $senderName = null,
    ) {}

    public function send(string $to, string $text): string
    {
        $response = $this->post($to, $text, $this->token());
        // Jeton refusé (expiré plus tôt que prévu, clés changées) : un nouveau, une seule fois
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey());
            $response = $this->post($to, $text, $this->token());
        }

        if ($response->failed()) {
            throw new SmsException(
                'SMS refusé par Orange ('.$response->status().') : '.$this->error($response),
                retryable: $response->serverError() || $response->status() === 429,
            );
        }

        // Identifiant du message : fin de l'adresse de la ressource créée
        $resource = (string) $response->json('outboundSMSMessageRequest.resourceURL');

        return $resource !== '' ? basename($resource) : '';
    }

    private function post(string $to, string $text, string $token): Response
    {
        $sender = 'tel:'.$this->senderAddress;
        $message = array_filter([
            'address' => 'tel:'.$to,
            'senderAddress' => $sender,
            'senderName' => $this->senderName ?: null,
            'outboundSMSTextMessage' => ['message' => $text],
        ]);

        try {
            return Http::withToken($token)->acceptJson()->asJson()->timeout(15)
                ->post(self::API.'/outbound/'.rawurlencode($sender).'/requests', ['outboundSMSMessageRequest' => $message]);
        } catch (ConnectionException $e) {
            throw new SmsException('Orange (SMS) injoignable : '.$e->getMessage(), retryable: true, previous: $e);
        }
    }

    private function token(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)->asForm()->acceptJson()->timeout(15)
                ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);
        } catch (ConnectionException $e) {
            throw new SmsException('Orange (SMS) injoignable : '.$e->getMessage(), retryable: true, previous: $e);
        }

        $token = (string) $response->json('access_token');
        if ($response->failed() || $token === '') {
            throw new SmsException('Orange refuse les identifiants SMS ('.$response->status().') : '.$this->error($response), retryable: $response->serverError());
        }

        // Valable une heure : gardé un peu moins longtemps
        Cache::put($this->cacheKey(), $token, now()->addSeconds(max(60, (int) ($response->json('expires_in') ?: 3600) - 120)));

        return $token;
    }

    private function cacheKey(): string
    {
        return 'orange-sms-token:'.md5($this->clientId);
    }

    // Messages d'erreur d'Orange (« Not enough credit », expéditeur inconnu…)
    private function error(Response $response): string
    {
        foreach (['requestError.policyException', 'requestError.serviceException'] as $path) {
            if ($text = $response->json($path.'.text')) {
                // « Policy error: %1 » + variables ["Not enough credit"] → « Policy error: Not enough credit »
                foreach (array_values((array) $response->json($path.'.variables', [])) as $i => $value) {
                    $text = str_replace('%'.($i + 1), (string) $value, $text);
                }

                return $text;
            }
        }

        return (string) ($response->json('error_description') ?? $response->json('description') ?? $response->json('message') ?? mb_substr($response->body(), 0, 200));
    }
}
