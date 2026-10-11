<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use App\Models\Place;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lieux (recherche, ajout d'un quartier manquant) et zones de livraison de la boutique.
 */
class DeliveryController extends Controller
{
    public function __construct(private readonly Places $places) {}

    public function places(Request $request, PlaceMatcher $matcher): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', 'in:city,commune,neighborhood']]);
        $all = $this->places->forStore($this->currentStore());

        $found = filled($request->query('search'))
            ? $matcher->search($request->query('search'), $all, 15)
            : $all->when($request->query('type'), fn ($c, $type) => $c->where('type', $type))->sortBy('name')->values();

        return response()->json(['data' => $found->map(fn (Place $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'label' => $p->label(),
            'type' => $p->type,
            'parent_id' => $p->parent_id,
            'is_custom' => $p->store_id !== null,
        ])]);
    }

    /**
     * Quartier absent du référentiel : ajouté pour cette boutique seulement.
     */
    public function addPlace(Request $request): JsonResponse
    {
        $store = $this->currentStore();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'parent_id' => ['required', 'integer'],
        ], [], ['name' => 'nom', 'parent_id' => 'commune']);

        $parent = Place::visibleTo($store->id)->whereIn('type', ['city', 'commune'])->findOrFail($data['parent_id']);
        $place = Place::create([
            'store_id' => $store->id,
            'country' => $parent->country,
            'parent_id' => $parent->id,
            'type' => $parent->type === 'city' ? 'commune' : 'neighborhood',
            'name' => trim($data['name']),
            'slug' => Str::slug($data['name']),
        ]);
        $this->places->forget($store);

        return response()->json(['data' => ['id' => $place->id, 'label' => $place->load('parent')->label()]], 201);
    }

    public function zones(): JsonResponse
    {
        $zones = DeliveryZone::with('places.parent')->orderBy('sort')->orderBy('id')->get();

        return response()->json(['data' => $zones->map(fn (DeliveryZone $z) => $this->present($z))]);
    }

    public function storeZone(Request $request): JsonResponse
    {
        $zone = DB::transaction(function () use ($request) {
            $zone = DeliveryZone::create($this->validated($request) + ['store_id' => $this->currentStore()->id]);
            $this->syncPlaces($zone, $request->input('place_ids', []));

            return $zone;
        });

        return response()->json(['data' => $this->present($zone->load('places.parent'))], 201);
    }

    public function updateZone(Request $request, int $zone): JsonResponse
    {
        $model = DeliveryZone::findOrFail($zone);
        DB::transaction(function () use ($request, $model) {
            $model->update($this->validated($request));
            if ($request->has('place_ids')) {
                $this->syncPlaces($model, $request->input('place_ids', []));
            }
        });

        return response()->json(['data' => $this->present($model->load('places.parent'))]);
    }

    public function destroyZone(int $zone): JsonResponse
    {
        DeliveryZone::findOrFail($zone)->delete();

        return response()->json(['message' => 'Zone supprimée.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'fee' => ['required', 'integer', 'min:0', 'max:1000000'],
            'free_above' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'eta_label' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'place_ids' => ['sometimes', 'array', 'max:500'],
            'place_ids.*' => ['integer'],
        ], [], ['name' => 'nom', 'fee' => 'frais', 'free_above' => 'livraison offerte dès']);
    }

    /**
     * Un lieu n'appartient qu'à une zone de la boutique : l'ajouter ici le retire des autres.
     */
    private function syncPlaces(DeliveryZone $zone, array $ids): void
    {
        $store = $this->currentStore();
        $ids = Place::visibleTo($store->id)->whereIn('id', $ids)->pluck('id')->all();

        DB::table('delivery_zone_places')->where('store_id', $store->id)->whereIn('place_id', $ids)->where('delivery_zone_id', '!=', $zone->id)->delete();
        $zone->places()->sync(collect($ids)->mapWithKeys(fn ($id) => [$id => ['store_id' => $store->id]])->all());
    }

    private function present(DeliveryZone $zone): array
    {
        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'fee' => $zone->fee,
            'free_above' => $zone->free_above,
            'eta_label' => $zone->eta_label,
            'is_active' => $zone->is_active,
            'sort' => $zone->sort,
            'places' => $zone->places->map(fn (Place $p) => ['id' => $p->id, 'label' => $p->label()])->values(),
        ];
    }
}
