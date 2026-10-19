<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Models\CarrierConnection;
use App\Models\CarrierZone;
use App\Models\CarrierZoneMapping;
use App\Models\Courier;
use App\Models\Place;
use App\Models\ProductVariant;
use App\Services\Places\Places;
use App\Services\Shipping\ShipmentDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saisie d'une commande : qui peut livrer ce quartier (mes livreurs, entreprises de livraison) et à quel prix,
 * avant que la commande existe. Le prix affiché est celui qui sera enregistré.
 */
class DeliveryOptionsController extends Controller
{
    public function __invoke(Request $request, Places $places, ShipmentDesk $shipments): JsonResponse
    {
        $data = $request->validate([
            'place_id' => ['nullable', 'integer'],
            'items' => ['sometimes', 'array', 'max:50'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $store = $this->currentStore();
        $place = isset($data['place_id']) ? Place::visibleTo($store->id)->with('parent.parent')->find($data['place_id']) : null;
        $zone = $places->zoneFor($store, $place);

        // Poids et fragilité du colis (le prix des entreprises de livraison en dépend)
        $lines = collect($data['items'] ?? [])->filter(fn ($i) => ! empty($i['variant_id']));
        $variants = ProductVariant::with('product')->whereIn('id', $lines->pluck('variant_id'))->get()->keyBy('id');
        $grams = $lines->sum(fn ($i) => (int) $variants->get($i['variant_id'])?->weight_g * (int) ($i['quantity'] ?? 1));
        $fragile = $variants->contains(fn (ProductVariant $v) => $v->product?->is_fragile);

        $couriers = Courier::where('status', 'active')->with('zones:id')
            ->withCount(['shipments as active_missions' => fn ($q) => $q->whereIn('status', ['awaiting_pickup', 'in_transit', 'out_for_delivery'])])
            ->orderBy('name')->get()
            ->map(fn (Courier $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_available' => $c->is_available,
                'active_missions' => $c->active_missions,
                'covers_zone' => $zone !== null && $c->zones->contains('id', $zone->id),
            ])
            ->sortBy(fn ($c) => [$c['covers_zone'] ? 0 : 1, $c['is_available'] ? 0 : 1, $c['active_missions']])->values();

        $connections = CarrierConnection::where('status', 'active')->get()->keyBy('id');
        $carriers = array_map(fn (array $q) => [
            'id' => $q['connection_id'],
            'name' => $q['name'],
            'is_api' => $connections->get($q['connection_id'])?->isApi() ?? false,
            'zone' => $q['zone'],
            'fee' => $q['fee'],
            'covered' => $q['error'] === null,
            'error' => $q['error'],
        ], $shipments->quotesFor($store->id, $place?->id, $fragile, $grams ? round($grams / 1000, 2) : null));

        return response()->json(['data' => [
            'zone' => $zone ? ['id' => $zone->id, 'name' => $zone->name, 'fee' => $zone->fee, 'free_above' => $zone->free_above, 'eta_label' => $zone->eta_label] : null,
            'couriers' => $couriers,
            'carriers' => $carriers,
        ]]);
    }

    /**
     * Zones et tarifs d'une entreprise de livraison, avec les quartiers de chaque zone.
     */
    public function tariffs(int $carrier): JsonResponse
    {
        $connection = CarrierConnection::where('status', 'active')->findOrFail($carrier);
        $placesByZone = CarrierZoneMapping::where('connection_id', $connection->id)->whereNotNull('carrier_zone_id')
            ->with('place.parent')->get()
            ->groupBy('carrier_zone_id')
            ->map(fn ($mappings) => $mappings->map(fn ($m) => $m->place?->label())->filter()->sort()->values());

        return response()->json(['data' => [
            'name' => $connection->name,
            'is_api' => $connection->isApi(),
            'zones' => CarrierZone::where('connection_id', $connection->id)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (CarrierZone $z) => ['id' => $z->id, 'label' => $z->label(), 'fee' => $z->fee, 'places' => $placesByZone->get($z->id, collect())]),
        ]]);
    }
}
