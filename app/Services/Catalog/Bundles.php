<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\BundleComponent;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Live\LiveImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    /** Combinaisons de tailles au plus dans un pack (une variante du pack par combinaison) */
    private const MAX_COMBINATIONS = 60;

    /**
     * Pack créé ou modifié en une fois (écran « Nouveau pack ») : ses articles, avec ou sans tailles, et son prix.
     * Un article à tailles devient un choix du pack (« Robe wax : S, M, L ») : chaque combinaison est une variante du
     * pack qui sort du stock la bonne taille. Sans photo, le pack reçoit un montage des photos de ses articles.
     *
     * @param  array{title: string, price: int, description?: ?string, items: list<array{product_id: int, quantity?: int}>, collection_ids?: list<int>}  $data
     */
    public function build(Store $store, ?Product $pack, array $data, ?User $actor = null): Product
    {
        $items = collect($data['items'] ?? [])->map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => max(1, (int) ($i['quantity'] ?? 1))])
            ->groupBy('product_id')->map(fn ($g, $id) => ['product_id' => (int) $id, 'quantity' => (int) $g->sum('quantity')])->values();
        if ($items->sum('quantity') < 2) {
            throw new BusinessRuleException('Un pack contient au moins 2 articles.', 'items');
        }
        $products = Product::forStore($store->id)->with(['variants' => fn ($q) => $q->where('is_active', true), 'images'])
            ->whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');
        foreach ($items as $item) {
            $product = $products->get($item['product_id']) ?? throw new BusinessRuleException('Article introuvable.', 'items');
            if ($product->is_bundle || ($pack !== null && $product->id === $pack->id)) {
                throw new BusinessRuleException('Un pack ne peut pas contenir un autre pack.', 'items');
            }
            if ($product->variants->isEmpty()) {
                throw new BusinessRuleException("« {$product->title} » n'a plus de variante en vente.", 'items');
            }
        }

        // Articles à tailles : un choix du pack chacun (3 au plus)
        $choices = $items->filter(fn ($i) => $products[$i['product_id']]->has_variants)->values();
        if ($choices->count() > 3) {
            throw new BusinessRuleException('Trois articles avec des tailles ou des couleurs au plus dans un pack.', 'items');
        }
        $names = [];
        $options = $choices->map(function ($i) use ($products, &$names) {
            $name = Str::limit($products[$i['product_id']]->title, 40, '');
            $name = in_array($name, $names, true) ? $name.' '.(count($names) + 1) : $name;
            $names[] = $name;

            return ['name' => $name, 'values' => $products[$i['product_id']]->variants->map(fn (ProductVariant $v) => $v->title)->unique()->values()->all()];
        })->all();
        $combinations = [[]];
        foreach ($choices as $n => $choice) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($products[$choice['product_id']]->variants as $variant) {
                    $next[] = $combination + [$n => $variant];
                }
            }
            $combinations = $next;
        }
        if (count($combinations) > self::MAX_COMBINATIONS) {
            throw new BusinessRuleException('Trop de combinaisons de tailles dans ce pack ('.count($combinations).') : retirez un article à tailles.', 'items');
        }

        $price = max(0, (int) $data['price']);
        // Prix séparé de chaque combinaison : affiché barré quand le pack coûte moins
        $separately = fn (array $combination) => (int) $items->sum(fn ($i) => $i['quantity'] * (int) ($products[$i['product_id']]->has_variants
            ? $combination[$choices->search(fn ($c) => $c['product_id'] === $i['product_id'])]->price
            : $products[$i['product_id']]->variants->first()->price));
        $contents = $items->map(fn ($i) => '• '.($i['quantity'] > 1 ? $i['quantity'].' × ' : '').$products[$i['product_id']]->title)->implode("\n");

        $writer = app(CatalogWriter::class);
        $isNew = $pack === null;
        $pack = DB::transaction(function () use ($store, $pack, $data, $actor, $writer, $options, $combinations, $price, $separately, $contents, $items, $products, $choices) {
            $saved = $writer->save($store, $pack, array_filter([
                'title' => trim($data['title']),
                'description' => $data['description'] ?? ($pack === null ? $contents : null),
                'track_inventory' => false,
                'options' => $options,
                'variants' => array_map(fn (array $combination) => [
                    'options' => array_map(fn (ProductVariant $v) => $v->title, $combination),
                    'price' => $price,
                    'compare_at_price' => $separately($combination) > $price ? $separately($combination) : null,
                ], $combinations),
                'collection_ids' => $data['collection_ids'] ?? null,
            ], fn ($v) => $v !== null), $actor);
            $saved->forceFill(['is_bundle' => true, 'track_inventory' => false])->save();

            // Contenu de chaque variante du pack
            $variants = $saved->variants()->get();
            BundleComponent::forStore($store->id)->whereIn('bundle_variant_id', $variants->pluck('id'))->delete();
            foreach ($variants as $packVariant) {
                $values = array_values(array_filter([$packVariant->option1?->value, $packVariant->option2?->value, $packVariant->option3?->value], fn ($v) => $v !== null));
                foreach ($items as $item) {
                    $product = $products[$item['product_id']];
                    $n = $choices->search(fn ($c) => $c['product_id'] === $item['product_id']);
                    $component = $n === false
                        ? $product->variants->first()
                        : $product->variants->first(fn (ProductVariant $v) => mb_strtolower($v->title) === mb_strtolower((string) ($values[$n] ?? '')));
                    if ($component !== null) {
                        BundleComponent::create(['store_id' => $store->id, 'bundle_variant_id' => $packVariant->id, 'component_variant_id' => $component->id, 'quantity' => $item['quantity']]);
                    }
                }
            }

            return $saved;
        });

        // Photo : montage des photos des articles (seulement si le pack n'en a pas)
        if ($pack->images()->doesntExist()) {
            $this->photo($store, $pack, $items->map(fn ($i) => $products[$i['product_id']]->images->first())->filter()->values()->all());
        }

        return $pack->fresh(['options.values', 'variants', 'images', 'collections']);
    }

    /**
     * @param  list<ProductImage>  $images
     */
    private function photo(Store $store, Product $pack, array $images): void
    {
        try {
            $bytes = app(LiveImages::class)->montage($images);
            if ($bytes === null) {
                return;
            }
            $stored = app(ImageStore::class)->storeBytes($bytes, $store->id);
            $pack->images()->create(['store_id' => $store->id, 'path' => $stored['path'], 'width' => $stored['width'], 'height' => $stored['height'], 'alt' => $pack->title, 'position' => 0]);
        } catch (\Throwable $e) {
            Log::info('Pack : montage photo non fait', ['product' => $pack->id, 'error' => $e->getMessage()]);
        }
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
            // Pack avec tailles : la taille dépend du choix du client, on montre l'article seul
            'title' => $product->has_variants ? $c->component?->product?->title : $c->component?->fullTitle(),
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
