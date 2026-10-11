<?php

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Tag;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use App\Services\Stock\StockKeeper;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enregistre un produit avec ses options, ses variantes, ses collections et ses étiquettes en une fois.
 *
 * Données attendues (validées par ProductRequest) :
 *  - champs du produit (title, description, status…) ;
 *  - options : [['name' => 'Couleur', 'values' => ['Rouge', 'Bleu']], …] (3 au plus, vide = produit simple) ;
 *  - variants : [['id' => ?, 'options' => ['Rouge', 'M'], 'price' => 10000, 'on_hand' => ?, …], …].
 *    Toutes les combinaisons ne sont pas obligatoires. Une variante existante non envoyée est supprimée.
 *  - collection_ids, tags (noms), location_id (emplacement des quantités saisies, par défaut le principal).
 */
class CatalogWriter
{
    private const PRODUCT_FIELDS = [
        'title', 'description', 'brand', 'status', 'is_visible_online', 'track_inventory', 'allow_backorder',
        'requires_shipping', 'is_fragile', 'low_stock_threshold', 'seo_title', 'seo_description',
    ];

    private const VARIANT_FIELDS = ['sku', 'barcode', 'price', 'compare_at_price', 'cost_price', 'weight_g', 'image_id', 'is_active'];

    public function __construct(
        private readonly StockKeeper $stock,
        private readonly PlanLimits $limits,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Store $store, ?Product $product, array $data, ?User $actor = null): Product
    {
        if ($product === null) {
            $this->limits->ensureCanAdd($store, 'products', Product::forStore($store->id)->count());
        }

        return DB::transaction(function () use ($store, $product, $data, $actor) {
            $product ??= new Product(['store_id' => $store->id]);
            $isNew = ! $product->exists;

            $product->fill(Arr::only($data, self::PRODUCT_FIELDS));
            $product->store_id = $store->id;
            $product->status ??= ProductStatus::Active;
            if (isset($data['slug']) || $isNew) {
                $product->slug = $this->uniqueSlug($store, $data['slug'] ?? $data['title'] ?? $product->title, $product->id);
            }
            if ($product->status === ProductStatus::Active && $product->published_at === null) {
                $product->published_at = now();
            }

            $options = $this->normalizeOptions($data['options'] ?? []);
            $product->has_variants = $options !== [];
            $product->save();

            $valueIds = $this->syncOptions($product, $options);
            $location = $this->location($store, $data['location_id'] ?? null);
            $this->syncVariants($store, $product, $options, $valueIds, $data['variants'] ?? [], $location, $actor);
            $this->pruneOptions($product, $options);

            if (array_key_exists('collection_ids', $data)) {
                $ids = Collection::forStore($store->id)->whereIn('id', $data['collection_ids'] ?? [])->pluck('id');
                $product->collections()->sync($ids);
            }
            if (array_key_exists('tags', $data)) {
                $this->syncTags($store, $product, $data['tags'] ?? []);
            }

            $product->refreshPriceRange();

            return $product->fresh(['options.values', 'variants', 'images', 'collections', 'tags']);
        });
    }

    /**
     * Suppression (douce) d'un produit : ses variantes sortent du stock.
     */
    public function delete(Product $product, ?User $actor = null): void
    {
        DB::transaction(function () use ($product, $actor) {
            foreach ($product->variants as $variant) {
                $this->deleteVariant($variant, $actor);
            }
            $product->delete();
        });
    }

    /**
     * @param  array<int, array{name: string, values: list<string|array{value: string, swatch?: ?string}>}>  $options
     * @return list<array{name: string, values: list<array{value: string, swatch: ?string}>}>
     */
    private function normalizeOptions(array $options): array
    {
        // La validation peut réordonner les clés (ex. variants.1.id avant variants.0) : on suit les index
        ksort($options);
        $normalized = [];
        foreach (array_values($options) as $option) {
            $values = [];
            foreach ($option['values'] ?? [] as $value) {
                $value = is_array($value) ? $value : ['value' => $value];
                $text = trim((string) $value['value']);
                if ($text !== '' && ! in_array(mb_strtolower($text), array_map(fn ($v) => mb_strtolower($v['value']), $values), true)) {
                    $values[] = ['value' => $text, 'swatch' => $value['swatch'] ?? null];
                }
            }
            if (trim((string) ($option['name'] ?? '')) !== '' && $values !== []) {
                $normalized[] = ['name' => trim($option['name']), 'values' => $values];
            }
        }

        if (count($normalized) > 3) {
            throw new BusinessRuleException('Trois options au plus (ex. Couleur, Taille, Matière).', 'options');
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, int>> position (1..3) => [valeur en minuscules => id]
     */
    private function syncOptions(Product $product, array $options): array
    {
        $ids = [];
        foreach ($options as $index => $option) {
            $position = $index + 1;
            $model = ProductOption::updateOrCreate(['product_id' => $product->id, 'position' => $position], ['name' => $option['name']]);

            foreach ($option['values'] as $valuePosition => $value) {
                $existing = $model->values()->get()->first(fn (ProductOptionValue $v) => mb_strtolower($v->value) === mb_strtolower($value['value']));
                $existing ??= new ProductOptionValue(['option_id' => $model->id]);
                $existing->fill(['value' => $value['value'], 'position' => $valuePosition + 1, 'swatch' => $value['swatch']])->save();
                $ids[$position][mb_strtolower($value['value'])] = $existing->id;
            }
        }

        return $ids;
    }

    /**
     * Retire les options et valeurs qui ne servent plus (après la mise à jour des variantes).
     */
    private function pruneOptions(Product $product, array $options): void
    {
        $product->options()->where('position', '>', count($options))->get()->each(function (ProductOption $option) {
            $option->values()->delete();
            $option->delete();
        });

        foreach ($options as $index => $option) {
            $model = $product->options()->where('position', $index + 1)->first();
            $keep = array_map(fn ($v) => mb_strtolower($v['value']), $option['values']);
            $model?->values()->get()->reject(fn (ProductOptionValue $v) => in_array(mb_strtolower($v->value), $keep, true))->each->delete();
        }
    }

    private function syncVariants(Store $store, Product $product, array $options, array $valueIds, array $rows, StockLocation $location, ?User $actor): void
    {
        $existing = $product->variants()->get()->keyBy('id');
        $kept = [];
        $seen = [];

        if ($options === []) {
            // Produit simple : une seule variante, par défaut
            $row = $rows[0] ?? [];
            $rows = [array_merge($row, ['options' => []])];
        } elseif ($rows === []) {
            throw new BusinessRuleException('Ajoutez au moins une variante.', 'variants');
        }

        ksort($rows);
        foreach (array_values($rows) as $position => $row) {
            if (isset($row['options']) && is_array($row['options'])) {
                ksort($row['options']);
            }
            $combination = [];
            foreach ($options as $index => $option) {
                $text = mb_strtolower(trim((string) ($row['options'][$index] ?? '')));
                $id = $valueIds[$index + 1][$text] ?? null;
                if ($id === null) {
                    throw new BusinessRuleException('Variante n° '.($position + 1)." : choisissez une valeur de « {$option['name']} ».", "variants.{$position}.options");
                }
                $combination[$index + 1] = $id;
            }

            $key = implode('-', $combination) ?: 'default';
            if (isset($seen[$key])) {
                throw new BusinessRuleException('Deux variantes ont la même combinaison d\'options.', "variants.{$position}.options");
            }
            $seen[$key] = true;

            $variant = isset($row['id']) && $existing->has($row['id'])
                ? $existing->get($row['id'])
                : $existing->first(fn (ProductVariant $v) => ! in_array($v->id, $kept, true)
                    && $v->option1_value_id === ($combination[1] ?? null)
                    && $v->option2_value_id === ($combination[2] ?? null)
                    && $v->option3_value_id === ($combination[3] ?? null))
                ?? new ProductVariant(['store_id' => $store->id, 'product_id' => $product->id]);

            $this->ensureSkuAvailable($store, $row['sku'] ?? null, $variant->id, $position);
            if (isset($row['image_id']) && ! ProductImage::forStore($store->id)->where('product_id', $product->id)->whereKey($row['image_id'])->exists()) {
                $row['image_id'] = null;
            }

            $variant->fill(Arr::only($row, self::VARIANT_FIELDS));
            $variant->forceFill([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'option1_value_id' => $combination[1] ?? null,
                'option2_value_id' => $combination[2] ?? null,
                'option3_value_id' => $combination[3] ?? null,
                'title' => $options === [] ? 'Par défaut' : implode(' / ', array_map(
                    fn ($index) => $this->displayValue($options[$index], $row['options'][$index]),
                    array_keys($options),
                )),
                'is_default' => $options === [],
                'position' => $position,
                'price' => (int) ($row['price'] ?? $variant->price ?? 0),
                'is_active' => (bool) ($row['is_active'] ?? $variant->is_active ?? true),
            ])->save();
            $kept[] = $variant->id;

            if (isset($row['on_hand']) && $row['on_hand'] !== '') {
                $this->stock->setOnHand($actor, $variant->setRelation('product', $product), $location, (int) $row['on_hand'], StockMovementType::Adjustment, 'Fiche produit');
            }
        }

        // Variantes retirées : elles disparaissent après la mise à jour des autres (contrainte d'unicité)
        $existing->reject(fn (ProductVariant $v) => in_array($v->id, $kept, true))
            ->each(fn (ProductVariant $v) => $this->deleteVariant($v->setRelation('product', $product), $actor));
    }

    private function deleteVariant(ProductVariant $variant, ?User $actor): void
    {
        $levels = StockLevel::forStore($variant->store_id)->where('variant_id', $variant->id)->with('location')->get();

        if ($levels->sum('reserved') > 0) {
            throw new BusinessRuleException("« {$variant->fullTitle()} » est réservée par des commandes en cours : elle ne peut pas être supprimée.", 'variants');
        }

        foreach ($levels->where('on_hand', '!=', 0) as $level) {
            $this->stock->setOnHand($actor, $variant, $level->location, 0, StockMovementType::Adjustment, 'Variante supprimée');
        }

        // Supprimée d'abord (elle sort de l'index d'unicité), puis détachée de ses options pour qu'elles puissent
        // disparaître ; son nom reste dans l'historique
        $variant->delete();
        $variant->forceFill(['option1_value_id' => null, 'option2_value_id' => null, 'option3_value_id' => null])->saveQuietly();
    }

    private function displayValue(array $option, string $value): string
    {
        foreach ($option['values'] as $candidate) {
            if (mb_strtolower($candidate['value']) === mb_strtolower(trim($value))) {
                return $candidate['value'];
            }
        }

        return trim($value);
    }

    private function ensureSkuAvailable(Store $store, ?string $sku, ?int $variantId, int $position): void
    {
        if ($sku === null || $sku === '') {
            return;
        }

        $taken = ProductVariant::forStore($store->id)->where('sku', $sku)->when($variantId, fn ($q) => $q->whereKeyNot($variantId))->exists();
        if ($taken) {
            throw new BusinessRuleException("Le SKU « {$sku} » est déjà utilisé par un autre article.", "variants.{$position}.sku");
        }
    }

    private function syncTags(Store $store, Product $product, array $names): void
    {
        $ids = collect($names)->map(fn ($name) => trim((string) $name))->filter()->unique(fn ($n) => mb_strtolower($n))
            ->map(function (string $name) use ($store) {
                $tag = Tag::forStore($store->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

                return ($tag ?? Tag::create(['store_id' => $store->id, 'name' => mb_substr($name, 0, 40)]))->id;
            });

        $product->tags()->sync($ids);
    }

    private function location(Store $store, ?int $locationId): StockLocation
    {
        return $locationId
            ? StockLocation::forStore($store->id)->findOrFail($locationId)
            : $this->stock->defaultLocation($store);
    }

    public function uniqueSlug(Store $store, string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'produit';
        $base = Str::limit($base, 80, '');
        $slug = $base;
        $i = 2;

        while (Product::forStore($store->id)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
