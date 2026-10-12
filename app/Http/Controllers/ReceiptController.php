<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\View\View;

/**
 * Ticket de caisse d'une commande : articles, total, paiements, reste à payer. Le jeton du lien suffit (comme la
 * page de suivi) ; seul le nom du client apparaît.
 */
class ReceiptController extends Controller
{
    public function __invoke(string $token): View
    {
        $order = Order::where('tracking_token', $token)->where('status', '!=', OrderStatus::Cancelled->value)
            ->with(['items', 'store', 'payments' => fn ($q) => $q->where('status', 'succeeded')->orderBy('paid_at')])->firstOrFail();

        return view('storefront.receipt', ['order' => $order, 'store' => $order->store]);
    }
}
