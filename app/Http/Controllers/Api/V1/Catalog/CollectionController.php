<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CollectionController extends Controller
{
    public function index(): JsonResponse
    {
        $collections = Collection::withCount('products')->orderBy('title')->get()
            ->map(fn (Collection $c) => $this->present($c));

        return response()->json(['data' => $collections]);
    }

    public function show(int $collection): JsonResponse
    {
        $model = Collection::with(['products' => fn ($q) => $q->select('products.id', 'products.title', 'products.status', 'products.price_min')])->findOrFail($collection);

        return response()->json(['data' => $this->present($model) + [
            'products' => $model->products->map(fn (Product $p) => ['id' => $p->id, 'title' => $p->title, 'status' => $p->status->value, 'price_min' => $p->price_min]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $collection = DB::transaction(function () use ($data) {
            $collection = Collection::create(collect($data)->only(['title', 'description', 'is_visible_online', 'seo_title', 'seo_description'])->all() + [
                'store_id' => $this->currentStore()->id,
                'slug' => $this->uniqueSlug($data['title']),
            ]);
            $this->syncProducts($collection, $data['product_ids'] ?? null);

            return $collection;
        });

        return response()->json(['data' => $this->present($collection->loadCount('products'))], 201);
    }

    public function update(Request $request, int $collection): JsonResponse
    {
        $model = Collection::findOrFail($collection);
        $data = $this->validated($request);

        DB::transaction(function () use ($model, $data) {
            $model->update(collect($data)->only(['title', 'description', 'is_visible_online', 'seo_title', 'seo_description'])->all());
            $this->syncProducts($model, $data['product_ids'] ?? null);
        });

        return response()->json(['data' => $this->present($model->loadCount('products'))]);
    }

    public function destroy(int $collection): JsonResponse
    {
        Collection::findOrFail($collection)->delete();

        return response()->json(['message' => 'Collection supprimée.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_visible_online' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:160'],
            'product_ids' => ['sometimes', 'array', 'max:1000'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('store_id', $this->currentStore()->id)->whereNull('deleted_at')],
        ], [], ['title' => 'nom', 'product_ids.*' => 'produit']);
    }

    /**
     * Produits de la collection, dans l'ordre donné.
     */
    private function syncProducts(Collection $collection, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }

        $collection->products()->sync(collect($ids)->unique()->values()->mapWithKeys(fn ($id, $position) => [$id => ['position' => $position]])->all());
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'collection';
        $slug = $base;
        for ($i = 2; Collection::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function present(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'title' => $collection->title,
            'slug' => $collection->slug,
            'description' => $collection->description,
            'is_visible_online' => $collection->is_visible_online,
            'products_count' => $collection->products_count ?? null,
            'url' => $this->currentStore()->url('/collections/'.$collection->slug),
        ];
    }
}
