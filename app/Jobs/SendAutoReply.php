<?php

namespace App\Jobs;

use App\Services\Messaging\AutoReplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Réponse automatique à un message client, quelques dizaines de secondes après sa réception.
 */
class SendAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $messageId) {}

    public function handle(AutoReplier $replier): void
    {
        $replier->run($this->messageId);
    }
}
