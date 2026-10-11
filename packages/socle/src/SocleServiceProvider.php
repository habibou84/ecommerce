<?php

namespace Jibiat\Socle;

use Illuminate\Support\ServiceProvider;
use Jibiat\Socle\Sms\LogSmsGateway;
use Jibiat\Socle\Sms\SmsException;
use Jibiat\Socle\Sms\SmsGateway;
use Jibiat\Socle\Sms\TwilioSmsGateway;

class SocleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsGateway::class, function () {
            return match (config('services.sms.driver', 'log')) {
                'twilio' => new TwilioSmsGateway(
                    (string) config('services.sms.twilio.sid'),
                    (string) config('services.sms.twilio.token'),
                    (string) config('services.sms.twilio.from'),
                ),
                'log' => new LogSmsGateway,
                default => new class implements SmsGateway
                {
                    public function send(string $to, string $text): string
                    {
                        throw new SmsException("L'envoi de SMS n'est pas configuré.");
                    }
                },
            };
        });
    }
}
