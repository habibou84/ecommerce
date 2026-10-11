<?php

namespace App\Services\Places;

use App\Models\DeliveryZone;
use App\Models\Place;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Lieux visibles d'une boutique et zone de livraison d'un lieu.
 */
class Places
{
    /** @var array<int, Collection<int, Place>> */
    private array $cache = [];

    /**
     * @return Collection<int, Place>
     */
    public function forStore(Store $store): Collection
    {
        return $this->cache[$store->id] ??= Place::visibleTo($store->id)->with('parent.parent')->get();
    }

    public function forget(Store $store): void
    {
        unset($this->cache[$store->id]);
    }

    /**
     * Zone de livraison active du lieu, ou à défaut de sa commune, puis de sa ville.
     */
    public function zoneFor(Store $store, ?Place $place): ?DeliveryZone
    {
        if ($place === null) {
            return null;
        }

        $ids = array_values(array_filter([$place->id, $place->parent_id, $place->parent?->parent_id]));
        $zones = DeliveryZone::forStore($store->id)->where('is_active', true)
            ->whereHas('places', fn ($q) => $q->whereIn('places.id', $ids))
            ->with(['places' => fn ($q) => $q->whereIn('places.id', $ids)])
            ->get();

        // Le plus précis d'abord : quartier, puis commune, puis ville
        foreach ($ids as $id) {
            if ($zone = $zones->first(fn (DeliveryZone $z) => $z->places->contains('id', $id))) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Zone par défaut d'une nouvelle boutique : Abidjan entier, frais à vérifier par le marchand.
     */
    public function provisionDefaultZone(Store $store): void
    {
        if (DeliveryZone::forStore($store->id)->exists()) {
            return;
        }

        $abidjan = Place::whereNull('store_id')->whereNull('parent_id')->where('slug', 'abidjan')->first();
        if ($abidjan === null) {
            return;
        }

        $zone = DeliveryZone::create(['store_id' => $store->id, 'name' => 'Abidjan', 'fee' => 1500, 'eta_label' => 'Livré sous 24 h']);
        $zone->places()->attach($abidjan->id, ['store_id' => $store->id]);
    }
}
