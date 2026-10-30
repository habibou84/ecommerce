<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Services\Places\DeliveryRates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Prix de livraison sans réglages : lieux à toucher (communes puis quartiers) avec leur prix, prix retenu depuis une
 * commande, prix de départ, ville de la boutique et expéditions.
 */
class DeliveryRatesController extends Controller
{
    public function __construct(private readonly DeliveryRates $rates) {}

    public function show(): JsonResponse
    {
        $store = $this->currentStore();
        $home = $this->rates->home($store);

        return response()->json(['data' => [
            'home' => $home ? ['id' => $home->id, 'name' => $home->name] : null,
            'base_fee' => $this->rates->base($store),
            'places' => $home ? $this->rates->children($store, $home) : [],
            'cities' => $this->rates->cities($store),
            'expedition' => ['fee' => $store->expedition_fee, 'prepay' => $store->expedition_prepay],
            'companies' => $this->rates->companies(),
        ]]);
    }

    /**
     * Quartiers d'une commune (ou lieux sous un lieu).
     */
    public function children(int $place): JsonResponse
    {
        $store = $this->currentStore();
        $parent = Place::visibleTo($store->id)->with('parent')->findOrFail($place);

        return response()->json(['data' => [
            'place' => ['id' => $parent->id, 'name' => $parent->name, 'label' => $parent->label()],
            'places' => $this->rates->children($store, $parent),
        ]]);
    }

    /**
     * Prix retenu depuis une commande : pour un lieu (commune, quartier, ville) ou pour les expéditions.
     */
    public function remember(Request $request): JsonResponse
    {
        $data = $request->validate([
            'place_id' => ['required_without:expedition', 'nullable', 'integer'],
            'expedition' => ['sometimes', 'boolean'],
            'fee' => ['required', 'integer', 'min:0', 'max:1000000'],
        ], [], ['fee' => 'prix']);
        $store = $this->currentStore();

        if (! empty($data['expedition'])) {
            $store->forceFill(['expedition_fee' => $data['fee']])->save();
        } else {
            $this->rates->remember($store, Place::visibleTo($store->id)->with('parent.parent')->findOrFail($data['place_id']), $data['fee']);
        }

        return $this->show();
    }

    /**
     * Prix retenu retiré : le lieu reprend le prix de sa commune ou de la ville.
     */
    public function forget(int $place): JsonResponse
    {
        $store = $this->currentStore();
        $this->rates->forget($store, Place::visibleTo($store->id)->findOrFail($place));

        return $this->show();
    }

    /**
     * Ville de la boutique, prix de départ et expéditions.
     */
    public function update(Request $request): JsonResponse
    {
        $store = $this->currentStore();
        $data = $request->validate([
            'home_place_id' => ['sometimes', 'integer', Rule::exists('places', 'id')->whereNull('parent_id')],
            'base_fee' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'expedition_fee' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'expedition_prepay' => ['sometimes', 'boolean'],
        ], [], ['base_fee' => 'prix de départ', 'expedition_fee' => 'livraison jusqu\'à la gare']);

        if (isset($data['home_place_id'])) {
            Place::visibleTo($store->id)->findOrFail($data['home_place_id']);
        }
        $store->forceFill(array_intersect_key($data, array_flip(['home_place_id', 'expedition_fee', 'expedition_prepay'])))->save();
        if (isset($data['base_fee'])) {
            $this->rates->setBase($store->fresh(), $data['base_fee']);
        }

        return $this->show();
    }
}
