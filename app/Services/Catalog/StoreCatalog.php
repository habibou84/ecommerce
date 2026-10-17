<?php

namespace App\Services\Catalog;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\StoreTransfer;
use App\Models\User;
use App\Services\Accounts\StoreRoles;
use App\Services\Couriers\SharedCouriers;
use App\Services\Stock\StockKeeper;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue entre les boutiques d'une même adresse : copie d'un produit (photos, options, variantes, prix ; stock à zéro)
 * et transfert de stock. Une copie garde le produit et les variantes d'origine : c'est ainsi qu'on retrouve
 * le même article d'une boutique à l'autre (à défaut, par le SKU).
 */
class StoreCatalog
{
    public function __construct(
        private readonly StoreRoles $roles,
        private readonly CatalogWriter $writer,
        private readonly ImageStore $images,
        private readonly StockKeeper $stock,
    ) {}

    /**
     * Autres boutiques de l'adresse, avec la fiche du même produit si elle y existe.
     *
     * @return list<array{id: int, name: string, product_id: ?int, can_copy: bool}>
     */
    public function presence(User $actor, Product $product): array
    {
        $current = $product->store;
        $copyable = $this->roles->otherAddressStores($actor, $current, Permission::ProductsManage)->pluck('id')->all();

        return $this->roles->otherAddressStores($actor, $current, Permission::ProductsView)
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'product_id' => $this->counterpartProduct($product, $store)?->id,
                'can_copy' => in_array($store->id, $copyable, true),
            ])->all();
    }

    /**
     * Copie du produit dans une autre boutique de l'adresse.
     */
    public function copy(User $actor, Product $source, int $targetId): Product
    {
        $target = $this->roles->otherAddressStores($actor, $source->store, Permission::ProductsManage)->firstWhere('id', $targetId)
            ?? throw new BusinessRuleException('Vous ne gérez pas le catalogue de cette boutique.', 'store_id');
        if ($existing = $this->counterpartProduct($source, $target)) {
            throw new BusinessRuleException("« {$existing->title} » est déjà dans {$target->name}.", 'store_id');
        }

        $source->loadMissing(['options.values', 'variants.option1', 'variants.option2', 'variants.option3', 'images', 'collections:id,title', 'tags:id,name']);
        $rootProduct = $source->origin_product_id ?? $source->id;

        return SharedCouriers::within($target, fn () => DB::transaction(function () use ($actor, $source, $target, $rootProduct) {
            $titles = $source->collections->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();
            $copy = $this->writer->save($target, null, [
                ...$source->only(['title', 'description', 'brand', 'status', 'is_visible_online', 'track_inventory', 'allow_backorder',
                    'requires_shipping', 'is_fragile', 'low_stock_threshold', 'seo_title', 'seo_description']),
                'status' => $source->status->value,
                'options' => $source->options->map(fn ($o) => ['name' => $o->name, 'values' => $o->values->map(fn ($v) => ['value' => $v->value, 'swatch' => $v->swatch])->all()])->all(),
                'variants' => $source->variants->map(fn (ProductVariant $v) => [
                    'options' => array_values(array_filter([$v->option1?->value, $v->option2?->value, $v->option3?->value], fn ($x) => $x !== null)),
                    // Un SKU déjà pris dans l'autre boutique (autre article) n'est pas repris
                    'sku' => $v->sku !== null && ! ProductVariant::forStore($target->id)->where('sku', $v->sku)->exists() ? $v->sku : null,
                    ...$v->only(['barcode', 'price', 'compare_at_price', 'cost_price', 'weight_g', 'is_active']),
                ])->all(),
                // Collections de même nom dans l'autre boutique
                'collection_ids' => Collection::forStore($target->id)->get(['id', 'title'])
                    ->filter(fn ($c) => in_array(mb_strtolower($c->title), $titles, true))->pluck('id')->all(),
                'tags' => $source->tags->pluck('name')->all(),
            ], $actor);

            $copy->forceFill(['origin_product_id' => $rootProduct])->saveQuietly();

            $imageIds = [];
            foreach ($source->images as $image) {
                $imageIds[$image->id] = ProductImage::create([
                    'store_id' => $target->id,
                    'product_id' => $copy->id,
                    'path' => $this->images->copy($image->path, $target->id),
                    ...$image->only(['alt', 'width', 'height', 'position']),
                ])->id;
            }

            // Mêmes positions des deux côtés : la n-ième variante copiée vient de la n-ième d'origine
            foreach ($copy->variants()->get()->values() as $index => $variant) {
                $from = $source->variants->values()->get($index);
                $variant->forceFill([
                    'origin_variant_id' => $from->origin_variant_id ?? $from->id,
                    'image_id' => $from->image_id ? ($imageIds[$from->image_id] ?? null) : null,
                ])->saveQuietly();
            }

            return $copy->fresh(['options.values', 'variants', 'images', 'collections', 'tags']);
        }));
    }

    /**
     * Le même article dans une autre boutique : même variante d'origine, sinon même SKU.
     */
    public function counterpart(ProductVariant $variant, Store $target): ?ProductVariant
    {
        $root = $variant->origin_variant_id ?? $variant->id;
        $match = ProductVariant::forStore($target->id)
            ->where(fn ($q) => $q->where('origin_variant_id', $root)->orWhere('id', $root))
            ->whereHas('product', fn ($q) => $q->withoutGlobalScopes()->whereNull('deleted_at'))
            ->first();

        return $match ?? ($variant->sku === null ? null : ProductVariant::forStore($target->id)->where('sku', $variant->sku)->first());
    }

    /**
     * Envoi immédiat de stock vers une autre boutique de l'adresse.
     *
     * @param  array<int|string, int>  $lines  variant_id (boutique de départ) => quantité
     */
    public function transfer(User $actor, Store $from, int $toId, array $lines, ?string $note = null, ?int $locationId = null): StoreTransfer
    {
        $to = $this->roles->otherAddressStores($actor, $from, Permission::StockManage)->firstWhere('id', $toId)
            ?? throw new BusinessRuleException('Vous ne gérez pas le stock de cette boutique.', 'store_id');
        $lines = array_filter(array_map('intval', $lines), fn ($q) => $q !== 0);
        if ($lines === []) {
            throw new BusinessRuleException('Ajoutez au moins un article.', 'items');
        }

        $fromLocation = $locationId
            ? StockLocation::forStore($from->id)->where('is_active', true)->findOrFail($locationId)
            : $this->stock->defaultLocation($from);
        $toLocation = SharedCouriers::within($to, fn () => $this->stock->defaultLocation($to));

        $variants = ProductVariant::forStore($from->id)->with('product')->whereIn('id', array_keys($lines))->get()->keyBy('id');
        $pairs = [];
        foreach ($lines as $id => $quantity) {
            $variant = $variants->get((int) $id) ?? throw new BusinessRuleException('Article introuvable.', 'items');
            if ($quantity < 0) {
                throw new BusinessRuleException('La quantité doit être positive.', 'items');
            }
            if (! $variant->product->track_inventory) {
                throw new BusinessRuleException("« {$variant->fullTitle()} » n'est pas suivi en stock.", 'items');
            }
            $match = $this->counterpart($variant, $to)
                ?? throw new BusinessRuleException("« {$variant->fullTitle()} » n'existe pas dans {$to->name} : copiez d'abord le produit.", 'items');
            $pairs[] = [$variant, $match, $quantity];
        }

        return DB::transaction(function () use ($actor, $from, $to, $fromLocation, $toLocation, $pairs, $note) {
            $transfer = StoreTransfer::create([
                'from_store_id' => $from->id,
                'to_store_id' => $to->id,
                'from_location_id' => $fromLocation->id,
                'to_location_id' => $toLocation->id,
                'lines' => array_map(fn ($p) => ['from_variant_id' => $p[0]->id, 'to_variant_id' => $p[1]->id, 'title' => $p[0]->fullTitle(), 'quantity' => $p[2]], $pairs),
                'quantity' => array_sum(array_column($pairs, 2)),
                'note' => $note,
                'created_by' => $actor->id,
            ]);

            foreach ($pairs as [$variant, $match, $quantity]) {
                $this->stock->sendToStore($actor, $variant, $fromLocation, $quantity, $transfer, "Vers {$to->name}");
                SharedCouriers::within($to, fn () => $this->stock->receiveFromStore($actor, $match, $toLocation, $quantity, $transfer, "Depuis {$from->name}", $variant->cost_price));
            }

            return $transfer;
        });
    }

    /**
     * Transferts entre cette boutique et les autres de l'adresse, les plus récents d'abord.
     *
     * @return SupportCollection<int, StoreTransfer>
     */
    public function transfers(Store $store, int $limit = 50): SupportCollection
    {
        return StoreTransfer::with(['fromStore:id,name', 'toStore:id,name', 'author:id,name'])
            ->where(fn ($q) => $q->where('from_store_id', $store->id)->orWhere('to_store_id', $store->id))
            ->latest('id')->limit($limit)->get();
    }

    private function counterpartProduct(Product $product, Store $target): ?Product
    {
        $root = $product->origin_product_id ?? $product->id;
        $match = Product::forStore($target->id)->where(fn ($q) => $q->where('origin_product_id', $root)->orWhere('id', $root))->first();
        if ($match !== null) {
            return $match;
        }

        // Produits créés séparément des deux côtés : même SKU sur une variante
        $skus = ProductVariant::forStore($product->store_id)->where('product_id', $product->id)->whereNotNull('sku')->pluck('sku');

        return $skus->isEmpty() ? null : Product::forStore($target->id)
            ->whereIn('id', ProductVariant::forStore($target->id)->whereIn('sku', $skus)->select('product_id'))->first();
    }
}
