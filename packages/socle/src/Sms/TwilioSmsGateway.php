<?php

namespace Jibiat\Socle\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TwilioSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly string $sid,
        private readonly string $token,
        private readonly string $from,
    ) {}

    public function send(string $to, string $text): string
    {
        try {
            $response = Http::asForm()->withBasicAuth($this->sid, $this->token)->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", [
                    'To' => $to,
                    'From' => $this->from,
                    'Body' => $text,
                ]);
        } catch (ConnectionException $e) {
            throw new SmsException('Fournisseur SMS injoignable : '.$e->getMessage(), retryable: true, previous: $e);
        }

        if ($response->failed()) {
            throw new SmsException(
                'SMS refusé ('.$response->status().') : '.($response->json('message') ?? $response->body()),
                retryable: $response->serverError() || $response->status() === 429,
            );
        }

        return (string) $response->json('sid');
    }
}
