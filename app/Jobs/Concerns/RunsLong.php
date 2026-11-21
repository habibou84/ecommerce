<?php

namespace App\Jobs\Concerns;

/**
 * Tâche longue (export, IA) : file « long », traitée par son propre processus (deploy/supervisor.conf) pour ne pas
 * retarder les messages WhatsApp et les notifications.
 *
 * Avec la file en base, elle passe par la connexion « database-long » (config/queue.php) : son délai avant nouvel
 * essai (retry_after) dépasse la durée permise de la tâche. Sur la connexion « database » (90 s), une tâche encore
 * en cours au-delà était reprise une seconde fois par l'autre processus (export ou réponse en double).
 */
trait RunsLong
{
    protected function onLongQueue(): void
    {
        $this->onConnection(config('queue.default') === 'database' ? 'database-long' : null)->onQueue('long');
    }
}
