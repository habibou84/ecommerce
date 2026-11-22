<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsLong;
use App\Services\Support\SupportAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Réponse de l'assistant du support à un message WhatsApp, quelques secondes après (la personne a fini d'écrire).
 */
class AnswerSupportMessage implements ShouldQueue
{
    use Queueable, RunsLong;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $messageId)
    {
        $this->onLongQueue();
    }

    public function handle(SupportAgent $agent): void
    {
        $agent->run($this->messageId);
    }
}
