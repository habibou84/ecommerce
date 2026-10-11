<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\View\View;

/**
 * Page de suivi d'une commande pour le client : étapes, articles, montant restant. Le jeton du lien suffit ;
 * aucune donnée de contact n'est affichée.
 */
class TrackingController extends Controller
{
    public function __invoke(string $token): View
    {
        $order = Order::where('tracking_token', $token)->with(['items', 'store'])->firstOrFail();

        $steps = [
            ['label' => 'Commande reçue', 'done' => true],
            ['label' => 'Confirmée', 'done' => $order->confirmed_at !== null],
            ['label' => 'En route', 'done' => $order->shipped_at !== null],
            ['label' => $order->delivery_method === 'pickup' ? 'Retirée' : 'Livrée', 'done' => $order->status === OrderStatus::Completed],
        ];

        return view('storefront.tracking', ['order' => $order, 'store' => $order->store, 'steps' => $steps]);
    }
}
