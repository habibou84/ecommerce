<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\Stock\StockKeeper;
use App\Services\Stock\StockLevels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Niveaux de stock, ajustements, journal et recherche d'articles (réceptions, transferts).
 */
class LevelController extends Controller
{
    public function summary(StockLevels $levels): JsonResponse
    {
        return response()->json(['data' => $levels->summary($this->currentStore())]);
    }

    /**
     * Une ligne par variante suivie : quantités par emplacement (ou dans un emplacement), filtre bas / épuisé.
     */
    public function index(Request $request, StockLevels $levels): JsonResponse
    {
        $request->validate([
            'location_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:low,out'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $store = $this->currentStore();
        $search = trim((string) $request->query('search'));
        $locationId = $request->integer('location_id') ?: null;

        $availability = $levels->variantAvailability($store);
        $page = ProductVariant::query()
            ->joinSub($availability, 'a', 'a.variant_id', '=', 'product_variants.id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('products.title', 'ilike', "%{$search}%")
                ->orWhere('product_variants.sku', 'ilike', "%{$search}%")->orWhere('product_variants.barcode', $search)))
            ->when($request->query('filter') === 'out', fn ($q) => $q->where('a.available', '<=', 0))
            ->when($request->query('filter') === 'low', fn ($q) => $q->where('a.available', '>', 0)->whereColumn('a.available', '<=', 'a.threshold'))
            ->orderBy('products.title')->orderBy('product_variants.position')
            ->select('product_variants.*', 'a.on_hand as total_on_hand', 'a.available as total_available', 'a.threshold')
            ->with(['product:id,title', 'product.images' => fn ($q) => $q->orderBy('position'), 'levels'])
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $page->getCollection()->map(function (ProductVariant $v) use ($locationId) {
                $level = $locationId ? $v->levels->firstWhere('location_id', $locationId) : null;

                return [
                    'variant_id' => $v->id,
                    'product_id' => $v->product_id,
                    'title' => $v->fullTitle(),
                    'sku' => $v->sku,
                    'thumb' => $v->product->images->first()?->urls()['thumb'],
                    'threshold' => (int) $v->threshold,
                    'on_hand' => $locationId ? (int) ($level?->on_hand ?? 0) : (int) $v->total_on_hand,
                    'reserved' => $locationId ? (int) ($level?->reserved ?? 0) : (int) $v->levels->sum('reserved'),
                    'available' => $locationId ? (int) ($level?->available() ?? 0) : (int) $v->total_available,
                    'total_available' => (int) $v->total_available,
                    'levels' => $v->levels->map(fn (StockLevel $l) => ['location_id' => $l->location_id, 'on_hand' => $l->on_hand, 'reserved' => $l->reserved])->values(),
                ];
            }),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Correction manuelle : delta (entrée/sortie) ou quantité constatée (on_hand).
     */
    public function adjust(Request $request, StockKeeper $keeper): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'location_id' => ['required', 'integer'],
            'quantity' => ['required_without:on_hand', 'nullable', 'integer', 'between:-1000000,1000000', 'not_in:0'],
            'on_hand' => ['required_without:quantity', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['sometimes', Rule::in(['adjustment', 'damaged'])],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['quantity' => 'quantité', 'on_hand' => 'quantité en stock']);

        $variant = ProductVariant::with('product')->findOrFail($data['variant_id']);
        $location = StockLocation::where('is_active', true)->findOrFail($data['location_id']);
        $type = StockMovementType::from($data['reason'] ?? 'adjustment');

        $movement = isset($data['on_hand'])
            ? $keeper->setOnHand($request->user(), $variant, $location, $data['on_hand'], StockMovementType::Count, $data['note'] ?? 'Correction')
            : $keeper->adjust($request->user(), $variant, $location, $data['quantity'], $type, $data['note'] ?? null);

        $level = StockLevel::where('variant_id', $variant->id)->where('location_id', $location->id)->first();

        return response()->json(['data' => [
            'movement_id' => $movement?->id,
            'on_hand' => $level?->on_hand ?? 0,
            'reserved' => $level?->reserved ?? 0,
            'available' => $level?->available() ?? 0,
        ]]);
    }

    public function movements(Request $request): JsonResponse
    {
        $request->validate([
            'variant_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(StockMovementType::class)],
        ]);

        $page = StockMovement::query()
            ->when($request->query('variant_id'), fn ($q, $id) => $q->where('variant_id', $id))
            ->when($request->query('product_id'), fn ($q, $id) => $q->whereHas('variant', fn ($v) => $v->where('product_id', $id)))
            ->when($request->query('location_id'), fn ($q, $id) => $q->where('location_id', $id))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->with(['variant.product:id,title', 'location:id,name', 'user:id,name'])
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'created_at' => $m->created_at->toIso8601String(),
                'type' => $m->type->value,
                'type_label' => $m->type->label(),
                'variant' => $m->variant?->fullTitle(),
                'location' => $m->location?->name,
                'on_hand_change' => $m->on_hand_change,
                'reserved_change' => $m->reserved_change,
                'on_hand_after' => $m->on_hand_after,
                'note' => $m->note,
                'user' => $m->user?->name,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Recherche d'articles (variantes) pour les réceptions, transferts et inventaires.
     */
    public function variants(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'location_id' => ['nullable', 'integer']]);
        $search = trim((string) $request->query('search'));
        $locationId = $request->integer('location_id') ?: null;

        $variants = ProductVariant::query()
            ->whereHas('product')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->where('title', 'ilike', "%{$search}%"))
                ->orWhere('sku', 'ilike', "%{$search}%")->orWhere('barcode', $search)))
            ->with(['product:id,title', 'levels'])
            ->orderBy('product_id')->orderBy('position')
            ->limit(30)->get();

        return response()->json(['data' => $variants->map(fn (ProductVariant $v) => [
            'id' => $v->id,
            'title' => $v->fullTitle(),
            'sku' => $v->sku,
            'cost_price' => $v->cost_price,
            'available' => $locationId
                ? (int) ($v->levels->firstWhere('location_id', $locationId)?->available() ?? 0)
                : (int) $v->levels->sum(fn ($l) => $l->available()),
        ])]);
    }
}
