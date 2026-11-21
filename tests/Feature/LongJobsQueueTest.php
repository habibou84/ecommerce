<?php

namespace Tests\Feature;

use App\Jobs\AnswerSupportMessage;
use App\Jobs\DraftSignupProduct;
use App\Jobs\ExportStoreData;
use App\Jobs\SendWhatsAppMessage;
use Tests\TestCase;

/**
 * Tâches longues sur leur propre file et une connexion dont le délai de reprise dépasse leur durée permise : avec
 * la file en base (90 s), une tâche encore en cours était reprise une seconde fois par l'autre processus.
 */
class LongJobsQueueTest extends TestCase
{
    public function test_long_jobs_use_the_long_queue_with_a_retry_delay_above_their_timeout(): void
    {
        config(['queue.default' => 'database']);

        foreach ([new ExportStoreData(1), new DraftSignupProduct(1, 'm'), new AnswerSupportMessage(1)] as $job) {
            $this->assertSame('database-long', $job->connection, $job::class);
            $this->assertSame('long', $job->queue, $job::class);
            $this->assertGreaterThan($job->timeout, config('queue.connections.database-long.retry_after'), $job::class);
        }

        // Les messages restent sur la file principale
        $this->assertNull((new \ReflectionClass(SendWhatsAppMessage::class))->newInstanceWithoutConstructor()->queue);
    }

    public function test_other_queue_drivers_keep_their_connection(): void
    {
        config(['queue.default' => 'redis']);

        $job = new ExportStoreData(1);
        $this->assertNull($job->connection);
        $this->assertSame('long', $job->queue);
    }
}
