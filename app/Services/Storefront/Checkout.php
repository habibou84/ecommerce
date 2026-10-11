<?php

namespace App\Services\Storefront;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\QuotaExceededException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Place;
use App\Models\StockLevel;
use App\Models\StockLocation;
use App\Models\Store;
use App\Services\Billing\PlanLimits;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Commande en une page de la boutique en ligne : frais de livraison du quartier, puis création de la commande
 * (paiement à la livraison). La commande arrive dans la file « À confirmer » de la boutique.
 */
class Checkout
{
    public function __construct(
        private readonly CartService $carts,
        private readonly Places $places,
        private readonly OrderDesk $desk,
    ) {}

    /**
     * Frais et délai pour un lieu (null : hors des zones de la boutique, frais à confirmer).
     *
     * @return array{fee: ?int, zone: ?string, eta: ?string, subtotal: int, discount: int, total: ?int, free_above: ?int}
     */
    public function quote(Store $store, Cart $cart, string $method, ?int $placeId): array
    {
        $summary = $this->carts->summary($store, $cart);
        $subtotal = $summary['subtotal'];
        $discount = $summary['discount']['amount'] ?? 0;

        if ($method === 'pickup') {
            return ['fee' => 0, 'zone' => null, 'eta' => null, 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $subtotal - $discount, 'free_above' => null];
        }

        $place = $placeId ? Place::visibleTo($store->id)->with('parent.parent')->find($placeId) : null;
        $zone = $this->places->zoneFor($store, $place);
        $fee = ($summary['discount']['free_shipping'] ?? false) && $zone ? 0 : $zone?->feeFor($subtotal, $store->free_shipping_above);

        return [
            'fee' => $fee,
            'zone' => $zone?->name,
            'eta' => $zone?->eta_label,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $fee === null ? null : $subtotal - $discount + $fee,
            'free_above' => collect([$zone?->free_above, $store->free_shipping_above])->filter()->min(),
        ];
    }

    /**
     * Points de retrait proposés au client.
     *
     * @return Collection<int, StockLocation>
     */
    public function pickupPoints(Store $store)
    {
        return StockLocation::forStore($store->id)->where('is_active', true)->where('is_pickup_point', true)->orderBy('priority')->get();
    }

    /**
     * @param  array{name: string, phone: string, delivery_method: string, place_id: ?int, address: ?string, landmark: ?string, note: ?string, pickup_location_id: ?int}  $data
     */
    public function place(Store $store, Cart $cart, array $data): Order
    {
        $cart->phone = $data['phone']; // limite d'utilisation d'un code promo par client
        $summary = $this->carts->summary($store, $cart);
        if ($summary['lines'] === []) {
            throw new BusinessRuleException('Votre panier est vide.');
        }
        if (collect($summary['lines'])->contains('short', true)) {
            throw new BusinessRuleException('Certains articles ne sont plus disponibles en quantité suffisante : vérifiez votre panier.');
        }
        if ($summary['discount']['error'] ?? null) {
            $cart->forceFill(['discount_code' => null])->save();

            throw new BusinessRuleException($summary['discount']['error'].' Il a été retiré : vérifiez le total avant de confirmer.', 'code');
        }

        $pickup = $data['delivery_method'] === 'pickup';
        if ($pickup && ! $this->pickupPoints($store)->contains('id', $data['pickup_location_id'] ?? null)) {
            throw new BusinessRuleException('Choisissez le point de retrait.', 'pickup_location_id');
        }
        if (! $pickup && empty($data['place_id'])) {
            throw new BusinessRuleException('Indiquez votre quartier.', 'place_id');
        }

        $quote = $this->quote($store, $cart, $data['delivery_method'], $data['place_id'] ?? null);

        try {
            app(PlanLimits::class)->ensureOrderQuota($store);
        } catch (QuotaExceededException) {
            // Le message de l'offre s'adresse au marchand : le client est invité à commander autrement
            throw new BusinessRuleException('La boutique ne peut pas prendre de nouvelle commande en ligne pour le moment. Contactez-la directement (WhatsApp ou téléphone).');
        }

        return DB::transaction(function () use ($store, $cart, $data, $summary, $pickup, $quote) {
            $order = $this->desk->create($store, [
                'source' => 'online_store',
                'customer_name' => $data['name'],
                'customer_phone' => $data['phone'],
                'delivery_method' => $data['delivery_method'],
                'shipping_place_id' => $pickup ? null : $data['place_id'],
                'shipping_address' => $pickup ? null : ($data['address'] ?? null),
                'shipping_landmark' => $pickup ? null : ($data['landmark'] ?? null),
                'pickup_location_id' => $pickup ? $data['pickup_location_id'] : null,
                'location_id' => $pickup ? $data['pickup_location_id'] : $this->fulfillingLocation($store, $summary['lines'])?->id,
                'customer_note' => $data['note'] ?? null,
                'internal_note' => ! $pickup && $quote['fee'] === null ? 'Quartier hors des zones de livraison : frais à confirmer avec le client.' : null,
                'utm' => $cart->utm,
                'discount_code' => $summary['discount']['code'] ?? null,
                'items' => array_map(fn ($line) => ['variant_id' => $line['variant_id'], 'quantity' => $line['quantity']], $summary['lines']),
            ], null, 'customer');

            $cart->forceFill(['status' => 'converted', 'order_id' => $order->id, 'recovered_at' => $cart->recovery_sent_at ? now() : null, 'customer_id' => $order->customer_id, 'name' => $data['name'], 'phone' => $data['phone']])->save();

            return $order;
        });
    }

    /**
     * Premier emplacement (par priorité) qui a tous les articles ; sinon l'emplacement principal.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function fulfillingLocation(Store $store, array $lines): ?StockLocation
    {
        $locations = StockLocation::forStore($store->id)->where('is_active', true)->where('fulfills_online_orders', true)
            ->orderBy('priority')->orderBy('id')->get();

        foreach ($locations as $location) {
            $levels = StockLevel::forStore($store->id)->where('location_id', $location->id)
                ->whereIn('variant_id', array_column($lines, 'variant_id'))->get()->keyBy('variant_id');

            $enough = collect($lines)->every(fn ($line) => $line['max'] === null || ($levels->get($line['variant_id'])?->available() ?? 0) >= $line['quantity']);
            if ($enough) {
                return $location;
            }
        }

        return $locations->first();
    }
}
