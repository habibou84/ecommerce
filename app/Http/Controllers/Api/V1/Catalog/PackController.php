<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\BundleComponent;
use App\Models\PackOffer;
use App\Models\Product;
use App\Services\Catalog\Bundles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Écran « Packs » : packs fixes (articles choisis, tailles comprises, un prix) et packs au choix (X articles parmi une
 * catégorie ou une sélection pour un prix).
 */
class PackController extends Controller
{
    public function __construct(private readonly Bundles $bundles) {}

    public function index(): JsonResponse
    {
        $store = $this->currentStore();
        $packs = Product::forStore($store->id)->where('is_bundle', true)->with(['images', 'variants:id,product_id,price,compare_at_price'])->latest('id')->get()
            ->map(fn (Product $p) => [
                'id' => $p->id, 'title' => $p->title, 'status' => $p->status?->value,
                'price' => (int) $p->variants->min('price'), 'compare_at' => $p->variants->max('compare_at_price'),
                'image' => $p->images->first()?->urls()['thumb'], 'choices' => $p->has_variants,
                'contents' => collect($this->bundles->components($p))->pluck('title')->implode(' + '),
            ]);
        $offers = PackOffer::forStore($store->id)->with(['collection:id,title', 'products:id,title'])->latest('id')->get()->map(fn (PackOffer $o) => $this->offer($o));

        return response()->json(['data' => ['packs' => $packs, 'offers' => $offers]]);
    }

    /** Pack fixe à modifier : son prix et ses articles */
    public function show(int $product): JsonResponse
    {
        $pack = Product::where('is_bundle', true)->with('variants')->findOrFail($product);
        $first = $pack->variants->first();
        $items = $first === null ? collect() : BundleComponent::where('bundle_variant_id', $first->id)->with('component.product')->get()
            ->map(fn (BundleComponent $c) => ['product_id' => $c->component?->product_id, 'title' => $c->component?->product?->title, 'quantity' => $c->quantity])
            ->filter(fn ($i) => $i['product_id'] !== null)->values();

        return response()->json(['data' => ['id' => $pack->id, 'title' => $pack->title, 'price' => (int) $first?->price, 'items' => $items]]);
    }

    public function store(Request $request): JsonResponse
    {
        $pack = $this->bundles->build($this->currentStore(), null, $this->validated($request), $request->user());

        return response()->json(['data' => ['id' => $pack->id]], 201);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $pack = Product::where('is_bundle', true)->findOrFail($product);
        $pack = $this->bundles->build($this->currentStore(), $pack, $this->validated($request), $request->user());

        return response()->json(['data' => ['id' => $pack->id]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'price' => ['required', 'integer', 'min:1', 'max:100000000'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:50'],
        ], [], ['title' => 'nom du pack', 'price' => 'prix du pack', 'items' => 'articles du pack']);
    }

    public function storeOffer(Request $request): JsonResponse
    {
        $offer = PackOffer::create(['store_id' => $this->currentStore()->id] + $this->offerData($request));
        $this->syncOfferProducts($offer, $request);

        return response()->json(['data' => $this->offer($offer->fresh(['collection', 'products']))], 201);
    }

    public function updateOffer(Request $request, int $offer): JsonResponse
    {
        $model = PackOffer::findOrFail($offer);
        $model->update($this->offerData($request));
        $this->syncOfferProducts($model, $request);

        return response()->json(['data' => $this->offer($model->fresh(['collection', 'products']))]);
    }

    public function destroyOffer(int $offer): JsonResponse
    {
        PackOffer::findOrFail($offer)->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function offerData(Request $request): array
    {
        $storeId = $this->currentStore()->id;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:2', 'max:50'],
            'price' => ['required', 'integer', 'min:1', 'max:100000000'],
            'collection_id' => ['nullable', 'integer', Rule::exists('collections', 'id')->where('store_id', $storeId)],
            'product_ids' => ['nullable', 'array', 'max:200'],
            'product_ids.*' => ['integer'],
            'is_active' => ['sometimes', 'boolean'],
        ], [], ['title' => 'nom du pack', 'quantity' => 'nombre d\'articles', 'price' => 'prix du pack', 'collection_id' => 'catégorie']);
        if (empty($data['collection_id']) && count($data['product_ids'] ?? []) < 2) {
            abort(response()->json(['message' => 'Choisissez une catégorie, ou au moins 2 articles.', 'errors' => ['product_ids' => ['Choisissez une catégorie, ou au moins 2 articles.']]], 422));
        }

        return ['title' => $data['title'], 'quantity' => $data['quantity'], 'price' => $data['price'], 'collection_id' => $data['collection_id'] ?? null]
            + (array_key_exists('is_active', $data) ? ['is_active' => $data['is_active']] : []);
    }

    private function syncOfferProducts(PackOffer $offer, Request $request): void
    {
        $ids = $offer->collection_id !== null ? [] : Product::whereIn('id', (array) $request->input('product_ids', []))->pluck('id')->all();
        $offer->products()->sync($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(PackOffer $o): array
    {
        return [
            'id' => $o->id, 'title' => $o->title, 'quantity' => $o->quantity, 'price' => $o->price, 'is_active' => $o->is_active,
            'collection_id' => $o->collection_id, 'collection' => $o->collection?->title,
            'product_ids' => $o->products->pluck('id')->all(), 'products' => $o->products->pluck('title')->all(),
        ];
    }
}
