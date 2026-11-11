<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\ParcelLabel;
use App\Models\Store;
use App\Services\Catalog\ImageStore;
use Illuminate\View\View;

/**
 * Page ouverte en scannant le QR collé sur le colis : étiquette imprimée avec la commande (/colis/{jeton}) ou
 * étiquette pré-imprimée (/e/{code}). N'importe qui tenant le colis peut la voir (livreur compris) : la boutique
 * (logo, numéro, lien, publics) et l'avancement seulement. Jamais le code de livraison (qui prouve que le client a
 * reçu son colis), ni les données du client, ni les articles et les montants : ceux-là restent sur le lien de
 * suivi envoyé au client.
 */
class ParcelController extends Controller
{
    public function __construct(private readonly ImageStore $images) {}

    public function __invoke(string $token): View
    {
        $order = Order::where('label_token', $token)->with(['store', 'shipments.connection', 'shipments.courier'])->firstOrFail();

        return $this->page($order->store, $order, null);
    }

    /** Étiquette pré-imprimée : colis si elle est reliée à une commande, sinon seulement la boutique */
    public function label(string $code): View
    {
        $store = $this->currentStore();
        $label = ParcelLabel::forStore($store->id)->where('code', strtoupper($code))->firstOrFail();
        $order = $label->order_id ? Order::forStore($store->id)->with(['shipments.connection', 'shipments.courier'])->find($label->order_id) : null;

        return $this->page($store, $order, $label);
    }

    private function page(Store $store, ?Order $order, ?ParcelLabel $label): View
    {
        $shipment = $order?->shipments->reject(fn ($s) => in_array($s->status, [ShipmentStatus::Cancelled, ShipmentStatus::Rejected], true))->last();
        $steps = $order ? [
            ['label' => 'Commande reçue', 'done' => true],
            ['label' => 'Confirmée', 'done' => $order->confirmed_at !== null],
            ['label' => 'En route', 'done' => $order->shipped_at !== null],
            ['label' => $order->delivery_method === 'pickup' ? 'Retirée' : 'Livrée', 'done' => $order->status === OrderStatus::Completed],
        ] : [];

        return view('storefront.parcel', [
            'order' => $order, 'store' => $store, 'steps' => $steps, 'shipment' => $shipment, 'label' => $label,
            'logo' => $store->logo_path ? $this->images->urls($store->logo_path)['thumb'] : null,
        ]);
    }
}
