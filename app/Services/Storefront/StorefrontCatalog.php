<?php

namespace App\Services\Storefront;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue vu par les clients : produits actifs, visibles en ligne, et stock vendable en ligne
 * (emplacements actifs qui préparent les commandes en ligne).
 */
class StorefrontCatalog
{
    public function products(Store $store): Builder
    {
        return Product::forStore($store->id)
            ->where('status', ProductStatus::Active->value)
            ->where('is_visible_online', true)
            ->whereHas('variants', fn ($q) => $q->where('is_active', true));
    }

    public function findProduct(Store $store, string $slug): ?Product
    {
        return $this->products($store)->where('slug', $slug)
            ->with(['images', 'options.values', 'variants' => fn ($q) => $q->where('is_active', true)->with(['option1', 'option2', 'option3'])])
            ->first();
    }

    /**
     * Disponible en ligne par variante.
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function availability(Store $store, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return StockLevel::forStore($store->id)->whereIn('variant_id', $variantIds)
            ->whereHas('location', fn ($q) => $q->where('is_active', true)->where('fulfills_online_orders', true))
            ->groupBy('variant_id')
            ->select('variant_id', DB::raw('SUM(on_hand - reserved) AS available'))
            ->pluck('available', 'variant_id')
            ->map(fn ($v) => max(0, (int) $v))
            ->all();
    }

    /**
     * Quantité qu'on peut encore vendre (null = illimitée : stock non suivi ou vente en rupture permise).
     */
    public function sellable(ProductVariant $variant, array $availability): ?int
    {
        $product = $variant->product;
        if (! $product->track_inventory || $product->allow_backorder) {
            return null;
        }

        return $availability[$variant->id] ?? 0;
    }

    /**
     * Produits en vente pour les grilles : première photo, prix et disponibilité globale.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, array<string, mixed>>
     */
    public function cards(Store $store, Collection $products): Collection
    {
        $products->loadMissing(['images' => fn ($q) => $q->orderBy('position'), 'variants' => fn ($q) => $q->where('is_active', true)]);
        $availability = $this->availability($store, $products->flatMap->variants->pluck('id')->all());

        return $products->map(function (Product $p) use ($availability) {
            $variants = $p->variants->each->setRelation('product', $p);
            $inStock = $variants->contains(fn ($v) => ($this->sellable($v, $availability) ?? 1) > 0);
            $compare = $variants->pluck('compare_at_price')->filter()->max();

            return [
                'slug' => $p->slug,
                'title' => $p->title,
                'price_min' => $p->price_min,
                'price_max' => $p->price_max,
                'compare_at' => $compare && $compare > $p->price_min ? $compare : null,
                'image' => $p->images->first()?->urls(),
                'image_alt' => $p->images->first()?->alt ?? $p->title,
                'in_stock' => $inStock,
            ];
        });
    }
}
