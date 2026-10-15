<?php

namespace Tests\Feature\Sms;

use App\Providers\AppServiceProvider;
use App\Services\Sms\OrangeSmsGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jibiat\Socle\Sms\SmsException;
use Jibiat\Socle\Sms\SmsGateway;
use Tests\TestCase;

class OrangeSmsGatewayTest extends TestCase
{
    private const SEND_URL = 'https://api.orange.com/smsmessaging/v1/outbound/tel%3A%2B2250000/requests';

    private function gateway(): OrangeSmsGateway
    {
        return new OrangeSmsGateway('client-id', 'client-secret', '+2250000', 'JIBIAT');
    }

    public function test_sends_the_message_with_the_sender_name(): void
    {
        Http::fake([
            OrangeSmsGateway::TOKEN_URL => Http::response(['access_token' => 'jeton-1', 'expires_in' => 3600]),
            self::SEND_URL => Http::response(['outboundSMSMessageRequest' => ['resourceURL' => 'https://api.orange.com/smsmessaging/v1/outbound/tel%3A%2B2250000/requests/abc-123']], 201),
        ]);

        $this->assertSame('abc-123', $this->gateway()->send('+2250700000001', 'Votre code : 123456'));

        Http::assertSent(fn (Request $r) => $r->url() === OrangeSmsGateway::TOKEN_URL
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('client-id:client-secret'))
            && $r['grant_type'] === 'client_credentials');
        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL
            && $r->hasHeader('Authorization', 'Bearer jeton-1')
            && $r['outboundSMSMessageRequest'] === [
                'address' => 'tel:+2250700000001',
                'senderAddress' => 'tel:+2250000',
                'senderName' => 'JIBIAT',
                'outboundSMSTextMessage' => ['message' => 'Votre code : 123456'],
            ]);
    }

    public function test_the_token_is_reused_then_renewed_when_refused(): void
    {
        Http::fake([
            OrangeSmsGateway::TOKEN_URL => Http::sequence()
                ->push(['access_token' => 'jeton-1', 'expires_in' => 3600])
                ->push(['access_token' => 'jeton-2', 'expires_in' => 3600]),
            self::SEND_URL => Http::sequence()
                ->push(['outboundSMSMessageRequest' => ['resourceURL' => '…/requests/1']], 201)
                ->push(['outboundSMSMessageRequest' => ['resourceURL' => '…/requests/2']], 201)
                ->push(['requestError' => ['policyException' => ['text' => 'Invalid token']]], 401)
                ->push(['outboundSMSMessageRequest' => ['resourceURL' => '…/requests/3']], 201),
        ]);

        $this->gateway()->send('+2250700000001', 'a');
        $this->gateway()->send('+2250700000001', 'b');
        $this->assertSame('3', $this->gateway()->send('+2250700000001', 'c'));

        Http::assertSentCount(6); // 2 jetons, 4 envois
    }

    public function test_orange_errors_are_explained(): void
    {
        Http::fake([
            OrangeSmsGateway::TOKEN_URL => Http::response(['access_token' => 'jeton-1', 'expires_in' => 3600]),
            self::SEND_URL => Http::response(['requestError' => ['policyException' => ['messageId' => 'POL0001', 'text' => 'Policy error: %1', 'variables' => ['Not enough credit']]]], 403),
        ]);

        try {
            $this->gateway()->send('+2250700000001', 'a');
            $this->fail('Exception attendue');
        } catch (SmsException $e) {
            $this->assertSame('SMS refusé par Orange (403) : Policy error: Not enough credit', $e->getMessage());
            $this->assertFalse($e->retryable);
        }
    }

    public function test_wrong_credentials_are_reported(): void
    {
        Http::fake([OrangeSmsGateway::TOKEN_URL => Http::response(['error' => 'invalid_client', 'error_description' => 'The requested service needs credentials'], 401)]);

        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('Orange refuse les identifiants SMS (401) : The requested service needs credentials');
        $this->gateway()->send('+2250700000001', 'a');
    }

    public function test_the_orange_driver_is_chosen_by_configuration(): void
    {
        config(['services.sms.driver' => 'orange', 'services.sms.orange.client_id' => 'x', 'services.sms.orange.client_secret' => 'y']);
        $this->app->forgetInstance(SmsGateway::class);
        (new AppServiceProvider($this->app))->register();

        $this->assertInstanceOf(OrangeSmsGateway::class, $this->app->make(SmsGateway::class));
    }
}
