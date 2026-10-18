<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Services\Stock\StockKeeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réceptions de marchandise et fournisseurs.
 */
class ReceiptController extends Controller
{
    public function index(): JsonResponse
    {
        $page = StockReceipt::with(['location:id,name', 'supplier:id,name', 'author:id,name'])->withSum('items', 'quantity')->latest('received_at')->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (StockReceipt $r) => [
                'id' => $r->id,
                'received_at' => $r->received_at->toIso8601String(),
                'location' => $r->location?->name,
                'supplier' => $r->supplier?->name,
                'reference' => $r->reference,
                'units' => (int) $r->items_sum_quantity,
                'total_cost' => $r->total_cost,
                'author' => $r->author?->name,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $receipt): JsonResponse
    {
        $model = StockReceipt::with(['location:id,name', 'supplier:id,name', 'items.variant.product:id,title'])->findOrFail($receipt);

        return response()->json(['data' => [
            'id' => $model->id,
            'received_at' => $model->received_at->toIso8601String(),
            'location' => $model->location?->name,
            'supplier' => $model->supplier?->name,
            'reference' => $model->reference,
            'total_cost' => $model->total_cost,
            'items' => $model->items->map(fn ($i) => ['variant' => $i->variant?->fullTitle(), 'quantity' => $i->quantity, 'unit_cost' => $i->unit_cost]),
        ]]);
    }

    public function store(Request $request, StockKeeper $keeper): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:60'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ], [], ['location_id' => 'emplacement', 'items' => 'articles', 'items.*.quantity' => 'quantité', 'items.*.unit_cost' => 'coût unitaire']);

        $location = StockLocation::where('is_active', true)->findOrFail($data['location_id']);
        $supplier = isset($data['supplier_id'])
            ? Supplier::findOrFail($data['supplier_id'])
            : (filled($data['supplier_name'] ?? null) ? Supplier::firstOrCreate(['store_id' => $this->currentStore()->id, 'name' => trim($data['supplier_name'])]) : null);

        $variants = ProductVariant::with('product')->whereIn('id', collect($data['items'])->pluck('variant_id'))->get()->keyBy('id');
        $lines = collect($data['items'])->map(fn ($item) => [
            'variant' => $variants->get($item['variant_id']) ?? abort(404),
            'quantity' => (int) $item['quantity'],
            'unit_cost' => isset($item['unit_cost']) ? (int) $item['unit_cost'] : null,
        ])->all();

        $receipt = $keeper->receive($request->user(), $location, $lines, ['supplier_id' => $supplier?->id, 'reference' => $data['reference'] ?? null]);

        return response()->json(['data' => ['id' => $receipt->id]], 201);
    }
}
