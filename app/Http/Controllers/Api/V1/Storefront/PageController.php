<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Pages libres de la boutique en ligne (À propos, Livraison et retours, Conditions de vente…).
 */
class PageController extends Controller
{
    public const MAX_PAGES = 30;

    public function index(): JsonResponse
    {
        return response()->json(['data' => Page::forStore($this->currentStore()->id)->orderBy('title')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->currentStore();
        abort_if(Page::forStore($store->id)->count() >= self::MAX_PAGES, 422, 'Trente pages au plus.');
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($store->id, $data['slug'] ?? null ?: $data['title']);

        return response()->json(['data' => Page::create($data + ['store_id' => $store->id])], 201);
    }

    public function update(Request $request, int $page): JsonResponse
    {
        $model = Page::forStore($this->currentStore()->id)->findOrFail($page);
        $data = $this->validated($request, $model);
        if (array_key_exists('slug', $data)) {
            $data['slug'] = $this->uniqueSlug($model->store_id, $data['slug'] ?: $data['title'] ?? $model->title, $model->id);
        }
        $model->update($data);

        return response()->json(['data' => $model]);
    }

    public function destroy(int $page): JsonResponse
    {
        Page::forStore($this->currentStore()->id)->findOrFail($page)->delete();

        return response()->json(['message' => 'Page supprimée.']);
    }

    private function validated(Request $request, ?Page $page = null): array
    {
        $required = $page ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:80'],
            'body' => ['nullable', 'string', 'max:50000'],
            'is_visible' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:160'],
        ], [], ['title' => 'titre', 'slug' => 'adresse', 'body' => 'contenu']);
    }

    private function uniqueSlug(int $storeId, string $source, ?int $ignore = null): string
    {
        $base = Str::limit(Str::slug($source), 70, '') ?: 'page';
        $slug = $base;
        for ($i = 2; Page::forStore($storeId)->where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
