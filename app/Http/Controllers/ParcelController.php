<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use Illuminate\View\View;

/**
 * Page ouverte en scannant le QR de l'étiquette collée sur le colis. N'importe qui tenant le colis peut la voir
 * (livreur compris) : boutique, numéro et avancement seulement. Jamais le code de livraison (qui prouve que le
 * client a reçu son colis), ni les données du client, ni les articles et les montants : ceux-là restent sur le
 * lien de suivi envoyé au client.
 */
class ParcelController extends Controller
{
    public function __invoke(string $token): View
    {
        $order = Order::where('label_token', $token)->with(['store', 'shipments.connection', 'shipments.courier'])->firstOrFail();
        $shipment = $order->shipments->reject(fn ($s) => in_array($s->status, [ShipmentStatus::Cancelled, ShipmentStatus::Rejected], true))->last();

        $steps = [
            ['label' => 'Commande reçue', 'done' => true],
            ['label' => 'Confirmée', 'done' => $order->confirmed_at !== null],
            ['label' => 'En route', 'done' => $order->shipped_at !== null],
            ['label' => $order->delivery_method === 'pickup' ? 'Retirée' : 'Livrée', 'done' => $order->status === OrderStatus::Completed],
        ];

        return view('storefront.parcel', ['order' => $order, 'store' => $order->store, 'steps' => $steps, 'shipment' => $shipment]);
    }
}
