<?php

namespace App\Jobs;

use App\Models\ConversationMessage;
use App\Services\Live\LiveSales;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Capture d'écran reçue sur WhatsApp pendant un live : code de l'article lu sur la photo, puis réservation.
 */
class ReadLiveCapture implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $messageId) {}

    public function handle(LiveSales $live): void
    {
        $message = ConversationMessage::withoutGlobalScopes()->find($this->messageId);
        if ($message !== null) {
            $live->readCapture($message);
        }
    }
}
