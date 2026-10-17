<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\CatalogWriter;
use App\Services\Catalog\ImageStore;
use App\Services\Catalog\StoreCatalog;
use App\Services\Stock\StockLevels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly CatalogWriter $writer) {}

    /**
     * Liste du catalogue : recherche (nom, SKU, code-barres), statut, collection, stock bas ou épuisé.
     */
    public function index(Request $request, StockLevels $levels): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:draft,active,archived'],
            'collection_id' => ['nullable', 'integer'],
            'stock' => ['nullable', 'in:low,out'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $store = $this->currentStore();
        $search = trim((string) $request->query('search'));

        $query = Product::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('title', 'ilike', "%{$search}%")
                ->orWhereHas('variants', fn ($v) => $v->where('sku', 'ilike', "%{$search}%")->orWhere('barcode', $search))))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('collection_id'), fn ($q, $id) => $q->whereHas('collections', fn ($c) => $c->whereKey($id)))
            ->when($request->query('stock'), fn ($q, $filter) => $q->whereIn('id', $levels->productIdsWithStockIssue($store, $filter)))
            ->withCount('variants')
            ->with(['images' => fn ($q) => $q->orderBy('position')->limit(1)])
            ->latest('updated_at');

        $page = $query->paginate($request->integer('per_page', 30));
        $totals = $levels->totalsByProduct($store, $page->getCollection()->pluck('id')->all());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Product $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'has_variants' => $p->has_variants,
                'variants_count' => $p->variants_count,
                'price_min' => $p->price_min,
                'price_max' => $p->price_max,
                'track_inventory' => $p->track_inventory,
                'thumb' => $p->images->first()?->urls()['thumb'],
                'on_hand' => $totals[$p->id]['on_hand'] ?? 0,
                'available' => $totals[$p->id]['available'] ?? 0,
                'low_variants' => $totals[$p->id]['low'] ?? 0,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $product): ProductResource
    {
        return ProductResource::make(ProductResource::load(Product::findOrFail($product)));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->writer->save($this->currentStore(), null, $this->data($request), $request->user());

        return ProductResource::make(ProductResource::load($product))->response()->setStatusCode(201);
    }

    public function update(ProductRequest $request, int $product): ProductResource
    {
        $saved = $this->writer->save($this->currentStore(), Product::findOrFail($product), $this->data($request), $request->user());

        return ProductResource::make(ProductResource::load($saved));
    }

    /**
     * Les quantités saisies sur la fiche ne comptent que pour qui gère le stock.
     *
     * @return array<string, mixed>
     */
    private function data(ProductRequest $request): array
    {
        $data = $request->validated();
        if (! $request->user()->can(Permission::StockManage->value)) {
            $data['variants'] = array_map(fn (array $v) => array_diff_key($v, ['on_hand' => true]), $data['variants']);
        }

        return $data;
    }

    /**
     * Copie d'un produit (mêmes options et variantes, sans SKU, sans stock ni photos), en brouillon.
     */
    public function duplicate(Request $request, int $product): JsonResponse
    {
        $source = Product::with(['options.values', 'variants.option1', 'variants.option2', 'variants.option3', 'collections:id', 'tags:id,name'])->findOrFail($product);

        $copy = $this->writer->save($this->currentStore(), null, [
            ...$source->only(['description', 'brand', 'is_visible_online', 'track_inventory', 'allow_backorder', 'requires_shipping', 'is_fragile', 'low_stock_threshold']),
            'title' => $source->title.' (copie)',
            'status' => 'draft',
            'options' => $source->options->map(fn ($o) => ['name' => $o->name, 'values' => $o->values->pluck('value')->all()])->all(),
            'variants' => $source->variants->map(fn ($v) => [
                'options' => array_values(array_filter([$v->option1?->value, $v->option2?->value, $v->option3?->value], fn ($x) => $x !== null)),
                ...$v->only(['price', 'compare_at_price', 'cost_price', 'weight_g', 'is_active']),
            ])->all(),
            'collection_ids' => $source->collections->pluck('id')->all(),
            'tags' => $source->tags->pluck('name')->all(),
        ], $request->user());

        return ProductResource::make(ProductResource::load($copy))->response()->setStatusCode(201);
    }

    /**
     * Autres boutiques de l'adresse : le produit y est-il déjà ? peut-on l'y copier ?
     */
    public function stores(Request $request, int $product, StoreCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->presence($request->user(), Product::findOrFail($product))]);
    }

    /**
     * Copie dans une autre boutique de l'adresse (photos, options, variantes, prix ; stock à zéro).
     */
    public function copy(Request $request, int $product, StoreCatalog $catalog): JsonResponse
    {
        $data = $request->validate(['store_id' => ['required', 'integer']], [], ['store_id' => 'boutique']);
        $copy = $catalog->copy($request->user(), Product::findOrFail($product), (int) $data['store_id']);

        return response()->json([
            'data' => ['id' => $copy->id, 'store_id' => $copy->store_id],
            'message' => "« {$copy->title} » est maintenant aussi dans {$copy->store->name} (stock à zéro).",
        ], 201);
    }

    public function destroy(Request $request, int $product): JsonResponse
    {
        $model = Product::with('variants')->findOrFail($product);
        $this->writer->delete($model, $request->user());
        ProductImage::where('product_id', $model->id)->get()->each(fn ($image) => app(ImageStore::class)->delete($image->path));

        return response()->json(['message' => 'Produit supprimé.']);
    }
}
