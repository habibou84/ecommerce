<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Services\Customers\Loyalty;
use Illuminate\View\View;

/**
 * Page de suivi d'une commande pour le client : étapes, articles, montant restant. Le jeton du lien suffit ;
 * aucune donnée de contact n'est affichée.
 */
class TrackingController extends Controller
{
    public function __invoke(string $token, Loyalty $loyalty): View
    {
        $order = Order::where('tracking_token', $token)->with(['items', 'store', 'shipments.connection', 'shipments.courier'])->firstOrFail();
        // Expédition en cours (ou la dernière) : transporteur, numéro et lien de suivi
        $shipment = $order->shipments->reject(fn ($s) => in_array($s->status, [ShipmentStatus::Cancelled, ShipmentStatus::Rejected], true))->last();

        $steps = [
            ['label' => 'Commande reçue', 'done' => true],
            ['label' => 'Confirmée', 'done' => $order->confirmed_at !== null],
            ['label' => 'En route', 'done' => $order->shipped_at !== null],
            ['label' => $order->delivery_method === 'pickup' ? 'Retirée' : 'Livrée', 'done' => $order->status === OrderStatus::Completed],
        ];

        // Fidélité : points du client et son code de parrainage à partager
        $settings = $loyalty->settings($order->store);
        $rewards = $order->customer ? $loyalty->forCustomer($order->customer) : null;

        return view('storefront.tracking', ['order' => $order, 'store' => $order->store, 'steps' => $steps, 'shipment' => $shipment,
            'rewards' => $rewards, 'loyalty' => $settings]);
    }
}
