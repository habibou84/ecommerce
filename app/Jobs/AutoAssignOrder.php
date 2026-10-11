<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Shipping\DeliveryAdvisor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Commande confirmée, boutique en affectation automatique : expédition créée selon les règles.
 */
class AutoAssignOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $orderId) {}

    public function handle(DeliveryAdvisor $advisor): void
    {
        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if ($order !== null) {
            $advisor->autoAssign($order);
        }
    }
}
