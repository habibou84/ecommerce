<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Shipping\DeliveryAdvisor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Commande confirmée : expédition confiée à qui a été choisi (à la saisie ou plus tard), sinon (boutique en
 * affectation automatique) selon les règles.
 */
class AutoAssignOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $orderId) {}

    /**
     * Livreur de la boutique choisi : course créée tout de suite (la fiche ouverte juste après l'enregistrement la
     * montre déjà). Entreprise de livraison ou règles automatiques : en arrière-plan (appel à l'entreprise).
     */
    public static function launch(int $orderId): void
    {
        $courierPlanned = Order::withoutGlobalScopes()->whereKey($orderId)->whereNotNull('planned_courier_id')->exists();
        $courierPlanned ? self::dispatchSync($orderId) : self::dispatch($orderId);
    }

    public function handle(DeliveryAdvisor $advisor): void
    {
        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if ($order !== null) {
            $advisor->autoAssign($order);
        }
    }
}
