<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Enums\StockLocationType;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\StockLevel;
use App\Models\StockLocation;
use App\Services\Stock\StockKeeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index(StockKeeper $keeper): JsonResponse
    {
        $keeper->defaultLocation($this->currentStore());

        $units = StockLevel::groupBy('location_id')->selectRaw('location_id, SUM(on_hand) AS units')->pluck('units', 'location_id');

        return response()->json(['data' => StockLocation::orderByDesc('is_active')->orderBy('priority')->orderBy('id')->get()
            ->map(fn (StockLocation $l) => $this->present($l) + ['units' => (int) ($units[$l->id] ?? 0)])]);
    }

    public function store(Request $request): JsonResponse
    {
        $location = StockLocation::create($this->validated($request) + ['store_id' => $this->currentStore()->id]);

        return response()->json(['data' => $this->present($location)], 201);
    }

    public function update(Request $request, int $location): JsonResponse
    {
        $model = StockLocation::findOrFail($location);
        $data = $this->validated($request);

        if (($data['is_active'] ?? true) === false && $model->is_active) {
            if (StockLevel::where('location_id', $model->id)->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '>', 0))->exists()) {
                throw new BusinessRuleException('Cet emplacement contient encore du stock : transférez-le ou mettez-le à zéro avant de le désactiver.', 'is_active');
            }
            if (StockLocation::where('is_active', true)->count() <= 1) {
                throw new BusinessRuleException('Votre espace marchand doit garder au moins un emplacement actif.', 'is_active');
            }
        }

        $model->update($data);

        return response()->json(['data' => $this->present($model)]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(StockLocationType::manualValues())],
            'address' => ['nullable', 'string', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_pickup_point' => ['sometimes', 'boolean'],
            'fulfills_online_orders' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ], [], ['name' => 'nom', 'type' => 'type']);
    }

    private function present(StockLocation $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'type' => $location->type->value,
            'type_label' => $location->type->label(),
            'address' => $location->address,
            'landmark' => $location->landmark,
            'phone' => $location->phone,
            'is_pickup_point' => $location->is_pickup_point,
            'fulfills_online_orders' => $location->fulfills_online_orders,
            'priority' => $location->priority,
            'is_active' => $location->is_active,
        ];
    }
}
