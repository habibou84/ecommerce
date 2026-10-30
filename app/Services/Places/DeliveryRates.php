<?php

namespace App\Services\Places;

use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Place;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Prix de livraison sans réglages : la ville de la boutique (Abidjan par défaut) et son prix de départ, puis les prix
 * retenus commune par commune ou quartier par quartier depuis les commandes (une zone par lieu, la plus précise
 * l'emporte). Hors de la ville de la boutique, c'est une expédition : livraison jusqu'à la gare, puis compagnie de
 * transport.
 */
class DeliveryRates
{
    /** Compagnies de transport proposées au dépôt (complétées par celles déjà utilisées par la boutique) */
    public const COMPANIES = ['UTB', 'STIF', 'AVS', 'Sabé Transport', 'GDF Transport'];

    public function __construct(private readonly Places $places) {}

    /**
     * Ville de la boutique : celle choisie, sinon Abidjan.
     */
    public function home(Store $store): ?Place
    {
        return ($store->home_place_id ? Place::find($store->home_place_id) : null)
            ?? Place::whereNull('store_id')->whereNull('parent_id')->where('slug', 'abidjan')->first();
    }

    public function cityOf(Place $place): Place
    {
        $city = $place;
        while ($city->parent_id !== null && ($parent = $city->parent) !== null) {
            $city = $parent;
        }

        return $city;
    }

    /**
     * Lieu dans une autre ville que celle de la boutique : commande à expédier.
     */
    public function isExpedition(Store $store, ?Place $place): bool
    {
        $home = $this->home($store);

        return $place !== null && $home !== null && $this->cityOf($place->loadMissing('parent.parent'))->id !== $home->id;
    }

    /**
     * Lieux à toucher sous un lieu (communes de la ville, quartiers d'une commune), les plus livrés d'abord, avec
     * leur prix et s'il a été retenu pour ce lieu précis.
     *
     * @return list<array{id: int, name: string, label: string, type: string, fee: ?int, learned: bool, has_children: bool, uses: int}>
     */
    public function children(Store $store, Place $parent): array
    {
        $children = Place::visibleTo($store->id)->with('parent.parent')->where('parent_id', $parent->id)->where('is_active', true)->get();
        if ($children->isEmpty()) {
            return [];
        }
        $withChildren = Place::visibleTo($store->id)->whereIn('parent_id', $children->pluck('id'))->distinct()->pluck('parent_id')->flip();
        $own = $this->ownZones($store, $children->pluck('id')->all());
        $uses = $this->uses($store, $children->pluck('id')->all());

        return $children->map(fn (Place $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'label' => $p->label(),
            'type' => $p->type,
            'fee' => $this->places->zoneFor($store, $p)?->fee,
            'learned' => $own->has($p->id),
            'has_children' => $withChildren->has($p->id),
            'uses' => $uses[$p->id] ?? 0,
        ])->sort(fn (array $a, array $b) => [$b['uses'], $a['name']] <=> [$a['uses'], $b['name']])->values()->all();
    }

    /**
     * Autres villes (expéditions), les plus utilisées d'abord.
     *
     * @return list<array{id: int, name: string, uses: int}>
     */
    public function cities(Store $store): array
    {
        $home = $this->home($store);
        $cities = Place::visibleTo($store->id)->whereNull('parent_id')->where('is_active', true)->when($home, fn ($q) => $q->whereKeyNot($home->id))->get();
        $uses = Order::where('expedition_city_id', '!=', null)->selectRaw('expedition_city_id, count(*) as n')->groupBy('expedition_city_id')->pluck('n', 'expedition_city_id');

        return $cities->map(fn (Place $c) => ['id' => $c->id, 'name' => $c->name, 'uses' => (int) ($uses[$c->id] ?? 0)])
            ->sort(fn (array $a, array $b) => [$b['uses'], $a['name']] <=> [$a['uses'], $b['name']])->values()->all();
    }

    /**
     * Prix de départ : celui de la ville de la boutique.
     */
    public function base(Store $store): ?int
    {
        $home = $this->home($store);

        return $home ? $this->places->zoneFor($store, $home)?->fee : null;
    }

    public function setBase(Store $store, int $fee): void
    {
        $home = $this->home($store);
        if ($home !== null) {
            $this->remember($store, $home, $fee);
        }
    }

    /**
     * Prix retenu pour un lieu : sa propre zone, créée au besoin (le lieu quitte une zone de plusieurs lieux pour
     * que ce prix l'emporte).
     */
    public function remember(Store $store, Place $place, int $fee): DeliveryZone
    {
        $zones = DeliveryZone::forStore($store->id)->whereHas('places', fn ($q) => $q->where('places.id', $place->id))->withCount('places')->get();
        $own = $zones->first(fn (DeliveryZone $z) => $z->places_count === 1);
        if ($own !== null) {
            $own->forceFill(['fee' => $fee, 'is_active' => true])->save();

            return $own;
        }

        foreach ($zones as $zone) {
            $zone->places()->detach($place->id);
        }
        $around = $place->parent ? $this->places->zoneFor($store, $place->parent) : null;
        $own = DeliveryZone::create([
            'store_id' => $store->id, 'name' => $place->name, 'fee' => $fee,
            'eta_label' => $around?->eta_label ?? 'Livré sous 24 h', 'is_active' => true,
        ]);
        $own->places()->attach($place->id, ['store_id' => $store->id]);

        return $own;
    }

    /**
     * Prix retenu retiré : le lieu reprend celui de sa commune ou de sa ville.
     */
    public function forget(Store $store, Place $place): void
    {
        DeliveryZone::forStore($store->id)->whereHas('places', fn ($q) => $q->where('places.id', $place->id))->withCount('places')->get()
            ->filter(fn (DeliveryZone $z) => $z->places_count === 1)
            ->each(fn (DeliveryZone $z) => $z->delete());
    }

    /**
     * @return list<string>
     */
    public function companies(): array
    {
        $used = Order::whereNotNull('expedition_company')->selectRaw('expedition_company, count(*) as n')->groupBy('expedition_company')
            ->orderByDesc('n')->limit(10)->pluck('expedition_company')->all();

        return array_values(array_unique([...$used, ...self::COMPANIES]));
    }

    /**
     * Zones propres à un seul lieu, par lieu.
     *
     * @param  list<int>  $ids
     * @return Collection<int, DeliveryZone>
     */
    private function ownZones(Store $store, array $ids): Collection
    {
        return DeliveryZone::forStore($store->id)->where('is_active', true)->withCount('places')->with(['places' => fn ($q) => $q->whereIn('places.id', $ids)])
            ->whereHas('places', fn ($q) => $q->whereIn('places.id', $ids))->get()
            ->filter(fn (DeliveryZone $z) => $z->places_count === 1)
            ->mapWithKeys(fn (DeliveryZone $z) => [$z->places->first()->id => $z]);
    }

    /**
     * Commandes de la boutique par lieu (avec celles de ses quartiers).
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function uses(Store $store, array $ids): array
    {
        $direct = Order::whereIn('shipping_place_id', $ids)->selectRaw('shipping_place_id, count(*) as n')->groupBy('shipping_place_id')->pluck('n', 'shipping_place_id')->all();
        $below = Order::join('places', 'places.id', '=', 'orders.shipping_place_id')->whereIn('places.parent_id', $ids)
            ->selectRaw('places.parent_id as parent, count(*) as n')->groupBy('places.parent_id')->pluck('n', 'parent')->all();
        $all = [];
        foreach ($ids as $id) {
            $all[$id] = (int) ($direct[$id] ?? 0) + (int) ($below[$id] ?? 0);
        }

        return $all;
    }
}
