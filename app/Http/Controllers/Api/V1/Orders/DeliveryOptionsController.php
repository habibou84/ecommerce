<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\CarrierConnection;
use App\Models\CarrierZone;
use App\Models\CarrierZoneMapping;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Place;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Store;
use App\Services\Orders\DeliveryCalendar;
use App\Services\Partners\PartnerReferrals;
use App\Services\Places\DeliveryRates;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use App\Services\Shipping\CarrierDirectory;
use App\Services\Shipping\ShipmentDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jibiat\Socle\Places\Places as CommonPlaces;

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

        // Livreurs actifs, et ceux créés mais pas encore connectés (ils verront la course en ouvrant leur lien)
        $couriers = Courier::whereIn('status', ['active', 'invited'])->with('places:id')
            ->withCount(['shipments as active_missions' => fn ($q) => $q->whereIn('status', ['awaiting_pickup', 'in_transit', 'out_for_delivery'])])
            ->orderBy('name')->get()
            ->map(fn (Courier $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_available' => $c->is_available,
                'active_missions' => $c->active_missions,
                'covers_zone' => $c->coversPlace($place),
                'all_zones' => $c->places->isEmpty(),
                'connected' => $c->status === 'active',
            ])
            ->sortBy(fn ($c) => [$c['covers_zone'] ? 0 : 1, $c['is_available'] ? 0 : 1, $c['all_zones'] ? 1 : 0, $c['active_missions']])->values();

        $connections = CarrierConnection::where('status', 'active')->withCount(['zones' => fn ($q) => $q->where('is_active', true)])->get()->keyBy('id');
        $carriers = array_map(fn (array $q) => [
            'id' => $q['connection_id'],
            'name' => $q['name'],
            // Entreprise qui a invité la boutique : proposée en premier (« votre entreprise »)
            'partner' => $connections->has($q['connection_id']) && PartnerReferrals::isPartner($store, $connections->get($q['connection_id'])),
            'is_api' => $connections->get($q['connection_id'])?->isApi() ?? false,
            'zones_count' => $connections->get($q['connection_id'])?->zones_count ?? 0,
            'zone' => $q['zone'],
            'fee' => $q['fee'],
            'covered' => $q['error'] === null,
            'error' => $q['error'],
            'earliest_date' => $q['earliest_date'] ?? null,
            'day_message' => $q['day_message'] ?? null,
        ], $shipments->quotesFor($store->id, $place?->id, $fragile, $grams ? round($grams / 1000, 2) : null));
        usort($carriers, fn (array $a, array $b) => $b['partner'] <=> $a['partner']);

        // Récemment utilisés : les derniers livreurs et entreprises à qui la boutique a confié une course
        $recent = Shipment::latest('id')->limit(50)->get(['courier_id', 'carrier_connection_id'])
            ->map(fn (Shipment $s) => $s->courier_id ? "courier:{$s->courier_id}" : "carrier:{$s->carrier_connection_id}")
            ->unique()->take(3)->values();

        // Autre ville que celle de la boutique : expédition (livraison jusqu'à la gare, frais de la compagnie au dépôt)
        $rates = app(DeliveryRates::class);
        $expedition = null;
        if ($place !== null && $rates->isExpedition($store, $place)) {
            $city = $rates->cityOf($place);
            // Prix habituel pour cette ville : celui convenu (livraison + expédition) à la dernière expédition
            $usual = Order::where('expedition_city_id', $city->id)->where('expedition_payer', 'included')->whereNotIn('status', ['cancelled'])
                ->latest('id')->value('shipping_fee');
            $expedition = ['city' => $city->name, 'fee' => $usual !== null ? (int) $usual : $store->expedition_fee, 'usual' => $usual !== null, 'prepay' => true];
        }

        return response()->json(['data' => [
            // Lieu choisi et sa commune : pour proposer de retenir un prix tapé à la main
            'place' => $place ? ['id' => $place->id, 'name' => $place->name, 'type' => $place->type,
                'commune' => $place->type === 'neighborhood' && $place->parent ? ['id' => $place->parent->id, 'name' => $place->parent->name] : null] : null,
            'expedition' => $expedition,
            'zone' => $zone ? ['id' => $zone->id, 'name' => $zone->name, 'fee' => $zone->fee, 'free_above' => $zone->free_above, 'eta_label' => $zone->eta_label] : null,
            'couriers' => $couriers,
            'carriers' => $carriers,
            // Entreprises de l'annuaire pas encore partenaires qui livrent ce lieu : compte ouvert en un clic
            'directory' => $place !== null && $request->user()->can(Permission::CarriersManage->value) ? $this->directory($store, $place, $fragile, $grams) : [],
            'recent' => $recent,
            // Jours de livraison de la boutique : première date possible et jours fermés à griser
            'calendar' => ['earliest' => $calendar->earliest($store), 'days' => $calendar->days($store), 'cutoff' => $calendar->settings($store)['cutoff']],
        ]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function directory(Store $store, Place $place, bool $fragile, int $grams): array
    {
        $code = $place->commonCode();
        if ($code === null) {
            return [];
        }
        $pickup = CommonPlaces::inText((string) $store->address)[0]['code'] ?? null;

        return app(CarrierDirectory::class)->quotesFor($store, $code, $pickup, $fragile, $grams ? round($grams / 1000, 2) : null);
    }

    /**
     * Recherche du quartier de livraison. Entreprise de livraison choisie : seulement les quartiers qu'elle dessert,
     * avec sa zone et son tarif ; sinon les lieux de la boutique, avec la zone de la boutique et ses frais. Sans
     * recherche : les lieux les plus livrés (90 derniers jours) ; avec une recherche, ceux-là passent en tête
     * (« frequent »), pour que le quartier habituel se trouve tout de suite.
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

        // Lieux les plus livrés par la boutique : nombre de commandes des 90 derniers jours
        $frequent = Order::whereNotNull('shipping_place_id')->where('created_at', '>=', now()->subDays(90))
            ->toBase()->selectRaw('shipping_place_id, COUNT(*) AS n')->groupBy('shipping_place_id')
            ->orderByDesc('n')->limit(8)->pluck('n', 'shipping_place_id')->map(fn ($n) => (int) $n)->all();
        $present = function (Place $p) use ($places, $store, $frequent): array {
            $zone = $places->zoneFor($store, $p);

            return $this->place($p) + [
                'zone' => $zone?->name, 'zone_id' => $zone?->id, 'fee' => $zone?->fee,
                'frequent' => isset($frequent[$p->id]), 'orders_count' => $frequent[$p->id] ?? 0,
            ];
        };

        if (mb_strlen($search) < 2) {
            $byId = $all->keyBy('id');

            return response()->json(['data' => collect(array_keys($frequent))->map(fn ($id) => $byId->get($id))->filter()->map($present)->values()]);
        }

        return response()->json(['data' => $matcher->search($search, $all, 20)->map($present)
            ->sortByDesc(fn ($p) => $p['orders_count'])->values()]);
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
