<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\BundleComponent;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Lots (packs) : un produit sans variantes dont la vente sort du stock chaque article qu'il contient. Le lot n'a
 * pas de stock à lui : sa disponibilité est celle de l'article le plus juste.
 */
class Bundles
{
    /**
     * @param  list<array{variant_id: int, quantity: int}>  $components  vide : le produit redevient un produit simple
     */
    public function save(Product $product, array $components): Product
    {
        if ($product->has_variants && $components !== []) {
            throw new BusinessRuleException('Un lot est un produit sans tailles ni couleurs : ses articles portent leurs propres variantes.', 'components');
        }
        $variant = $product->variants()->firstOrFail();
        $variants = ProductVariant::forStore($product->store_id)->with('product')->whereIn('id', array_column($components, 'variant_id'))->get()->keyBy('id');

        return DB::transaction(function () use ($product, $variant, $components, $variants) {
            BundleComponent::forStore($product->store_id)->where('bundle_variant_id', $variant->id)->delete();
            foreach ($components as $row) {
                $component = $variants->get((int) $row['variant_id']) ?? throw new BusinessRuleException('Article introuvable.', 'components');
                if ($component->product_id === $product->id || $component->product?->is_bundle) {
                    throw new BusinessRuleException('Un lot ne peut pas contenir un autre lot ni lui-même.', 'components');
                }
                BundleComponent::create(['store_id' => $product->store_id, 'bundle_variant_id' => $variant->id, 'component_variant_id' => $component->id, 'quantity' => max(1, (int) $row['quantity'])]);
            }
            $isBundle = $components !== [];
            // Le stock suivi est celui des articles du lot, pas celui du lot
            $product->forceFill(['is_bundle' => $isBundle, 'track_inventory' => $isBundle ? false : $product->track_inventory])->save();

            return $product;
        });
    }

    /**
     * @return list<array{variant_id: int, title: string, quantity: int, price: int}>
     */
    public function components(Product $product): array
    {
        $variant = $product->variants()->first();
        if ($variant === null) {
            return [];
        }

        return $variant->components()->with('component.product')->get()->map(fn (BundleComponent $c) => [
            'variant_id' => $c->component_variant_id,
            'title' => $c->component?->fullTitle(),
            'quantity' => $c->quantity,
            'price' => (int) $c->component?->price,
        ])->all();
    }

    /**
     * Disponibilité des lots d'après celle de leurs articles.
     *
     * @param  list<int>  $bundleVariantIds
     * @param  callable(list<int>): array<int, int>  $availability  disponible par variante
     * @return array<int, int>
     */
    public function availability(array $bundleVariantIds, callable $availability): array
    {
        if ($bundleVariantIds === []) {
            return [];
        }
        $components = BundleComponent::withoutGlobalScopes()->with('component.product')->whereIn('bundle_variant_id', $bundleVariantIds)->get()->groupBy('bundle_variant_id');
        $stock = $availability($components->flatten()->pluck('component_variant_id')->unique()->values()->all());

        return $components->map(fn ($list) => (int) $list->min(function (BundleComponent $c) use ($stock) {
            $product = $c->component?->product;
            if ($product === null) {
                return 0;
            }

            return ! $product->track_inventory || $product->allow_backorder ? PHP_INT_MAX : intdiv($stock[$c->component_variant_id] ?? 0, $c->quantity);
        }))->all();
    }
}
