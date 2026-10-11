<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Marketing\Conversions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Achat envoyé à l'API Conversions de Meta, hors de la requête du client.
 */
class SendPurchaseConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<string, ?string>  $context
     */
    public function __construct(public readonly int $orderId, public readonly array $context = []) {}

    public function handle(Conversions $conversions): void
    {
        $order = Order::withoutGlobalScopes()->with('store')->find($this->orderId);
        if ($order !== null && ! $conversions->sendPurchase($order, $this->context) && $order->conversion_sent_at === null && $this->attempts() < $this->tries) {
            $this->release($this->backoff);
        }
    }
}
