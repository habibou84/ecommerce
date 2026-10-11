<?php

namespace Jibiat\Socle\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Développement et démonstration : le SMS est écrit dans le journal au lieu d'être envoyé.
 */
class LogSmsGateway implements SmsGateway
{
    /** @var list<array{to: string, text: string}> Messages « envoyés » (tests) */
    public array $sent = [];

    public function send(string $to, string $text): string
    {
        $this->sent[] = ['to' => $to, 'text' => $text];
        Log::info('SMS (non envoyé, pilote log)', ['to' => $to, 'text' => $text]);

        return 'log-'.Str::uuid();
    }
}
