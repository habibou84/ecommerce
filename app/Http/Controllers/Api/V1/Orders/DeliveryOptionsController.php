<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Models\CarrierConnection;
use App\Models\CarrierZone;
use App\Models\CarrierZoneMapping;
use App\Models\Courier;
use App\Models\Place;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Services\Orders\DeliveryCalendar;
use App\Services\Places\PlaceMatcher;
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
    public function __invoke(Request $request, Places $places, ShipmentDesk $shipments, DeliveryCalendar $calendar): JsonResponse
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

        $connections = CarrierConnection::where('status', 'active')->withCount(['zones' => fn ($q) => $q->where('is_active', true)])->get()->keyBy('id');
        $carriers = array_map(fn (array $q) => [
            'id' => $q['connection_id'],
            'name' => $q['name'],
            'is_api' => $connections->get($q['connection_id'])?->isApi() ?? false,
            'zones_count' => $connections->get($q['connection_id'])?->zones_count ?? 0,
            'zone' => $q['zone'],
            'fee' => $q['fee'],
            'covered' => $q['error'] === null,
            'error' => $q['error'],
            'earliest_date' => $q['earliest_date'] ?? null,
            'day_message' => $q['day_message'] ?? null,
        ], $shipments->quotesFor($store->id, $place?->id, $fragile, $grams ? round($grams / 1000, 2) : null));

        // Récemment utilisés : les derniers livreurs et entreprises à qui la boutique a confié une course
        $recent = Shipment::latest('id')->limit(50)->get(['courier_id', 'carrier_connection_id'])
            ->map(fn (Shipment $s) => $s->courier_id ? "courier:{$s->courier_id}" : "carrier:{$s->carrier_connection_id}")
            ->unique()->take(3)->values();

        return response()->json(['data' => [
            'zone' => $zone ? ['id' => $zone->id, 'name' => $zone->name, 'fee' => $zone->fee, 'free_above' => $zone->free_above, 'eta_label' => $zone->eta_label] : null,
            'couriers' => $couriers,
            'carriers' => $carriers,
            'recent' => $recent,
            // Jours de livraison de la boutique : première date possible et jours fermés à griser
            'calendar' => ['earliest' => $calendar->earliest($store), 'days' => $calendar->days($store), 'cutoff' => $calendar->settings($store)['cutoff']],
        ]]);
    }

    /**
     * Recherche du quartier de livraison. Entreprise de livraison choisie : seulement les quartiers qu'elle dessert,
     * avec sa zone et son tarif ; sinon les lieux de la boutique, avec la zone de la boutique et ses frais.
     */
    public function places(Request $request, Places $places, PlaceMatcher $matcher): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'carrier_connection_id' => ['nullable', 'integer'],
        ]);
        $store = $this->currentStore();
        $all = $places->forStore($store);
        $search = trim((string) ($data['search'] ?? ''));

        if (! empty($data['carrier_connection_id'])) {
            $connection = CarrierConnection::where('status', 'active')->findOrFail($data['carrier_connection_id']);
            $mappings = CarrierZoneMapping::where('connection_id', $connection->id)->get()->keyBy('place_id');
            $zones = CarrierZone::where('connection_id', $connection->id)->where('is_active', true)->get()->keyBy('id');
            // Même règle que la course : le lieu, sinon son quartier parent, sa commune ; « non couvert » à la main l'emporte
            $zoneOf = function (Place $place) use ($mappings, $zones): ?CarrierZone {
                for ($current = $place; $current !== null; $current = $current->parent) {
                    $mapping = $mappings->get($current->id);
                    if ($mapping?->carrier_zone_id) {
                        return $zones->get($mapping->carrier_zone_id);
                    }
                    if ($mapping && $mapping->matched_by === 'manual') {
                        return null;
                    }
                }

                return null;
            };
            $covered = $all->filter(fn (Place $p) => in_array($p->type, ['commune', 'neighborhood'], true) && $zoneOf($p) !== null)->values();
            $found = $search === '' ? $covered->sortBy(fn (Place $p) => $p->label())->take(200)->values() : $matcher->search($search, $covered, 40);

            return response()->json(['data' => $found->map(function (Place $p) use ($zoneOf) {
                $zone = $zoneOf($p);

                return $this->place($p) + ['zone' => $zone->label(), 'zone_id' => $zone->id, 'fee' => $zone->fee];
            })->sortBy(fn ($p) => [$p['zone'], $p['label']])->values()]);
        }

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $matcher->search($search, $all, 20)->map(function (Place $p) use ($places, $store) {
            $zone = $places->zoneFor($store, $p);

            return $this->place($p) + ['zone' => $zone?->name, 'zone_id' => $zone?->id, 'fee' => $zone?->fee];
        })->values()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function place(Place $p): array
    {
        return [
            'id' => $p->id,
            'label' => $p->label(),
            'type' => $p->type,
            'ancestor_ids' => array_values(array_filter([$p->parent_id, $p->parent?->parent_id])),
        ];
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
