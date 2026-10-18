<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche produit complète (édition) : options, variantes avec stock par emplacement, photos, collections.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'brand' => $this->brand,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_visible_online' => $this->is_visible_online,
            'has_variants' => $this->has_variants,
            'track_inventory' => $this->track_inventory,
            'allow_backorder' => $this->allow_backorder,
            'is_bundle' => $this->is_bundle,
            'preorder_enabled' => $this->preorder_enabled,
            'preorder_ship_date' => $this->preorder_ship_date?->toDateString(),
            'preorder_deposit_percent' => $this->preorder_deposit_percent,
            'requires_shipping' => $this->requires_shipping,
            'is_fragile' => $this->is_fragile,
            'low_stock_threshold' => $this->low_stock_threshold,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'price_min' => $this->price_min,
            'price_max' => $this->price_max,
            'url' => $this->store?->url('/produits/'.$this->slug),
            'options' => $this->options->map(fn (ProductOption $o) => [
                'name' => $o->name,
                'values' => $o->values->map(fn ($v) => ['value' => $v->value, 'swatch' => $v->swatch])->values(),
            ]),
            'variants' => $this->variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'title' => $v->title,
                'options' => array_values(array_filter([$v->option1?->value, $v->option2?->value, $v->option3?->value], fn ($x) => $x !== null)),
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'price' => $v->price,
                'compare_at_price' => $v->compare_at_price,
                'cost_price' => $v->cost_price,
                'weight_g' => $v->weight_g,
                'image_id' => $v->image_id,
                'is_active' => $v->is_active,
                'levels' => $v->levels->map(fn ($l) => [
                    'location_id' => $l->location_id,
                    'on_hand' => $l->on_hand,
                    'reserved' => $l->reserved,
                    'available' => $l->available(),
                ])->values(),
                'on_hand' => $v->levels->sum('on_hand'),
                'available' => $v->levels->sum(fn ($l) => $l->available()),
            ]),
            'images' => $this->images->map(fn (ProductImage $i) => ['id' => $i->id, 'alt' => $i->alt, 'urls' => $i->urls()]),
            'collection_ids' => $this->collections->pluck('id'),
            'tags' => $this->tags->pluck('name'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Relations nécessaires à la fiche.
     */
    public static function load(Product $product): Product
    {
        return $product->load([
            'options.values', 'images', 'collections:id', 'tags:id,name', 'store',
            'variants.levels', 'variants.option1', 'variants.option2', 'variants.option3',
        ]);
    }
}
