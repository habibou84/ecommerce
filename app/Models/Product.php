<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Produit du catalogue. Tout ce qui se vend, se stocke et s'expédie est une variante :
 * un produit simple a une seule variante, « par défaut ».
 */
class Product extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id', 'title', 'slug', 'description', 'brand', 'status', 'is_visible_online', 'has_variants',
        'track_inventory', 'allow_backorder', 'requires_shipping', 'is_fragile', 'low_stock_threshold',
        'seo_title', 'seo_description', 'published_at', 'preorder_enabled', 'preorder_ship_date', 'preorder_deposit_percent',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'is_visible_online' => 'boolean',
            'has_variants' => 'boolean',
            'track_inventory' => 'boolean',
            'allow_backorder' => 'boolean',
            'requires_shipping' => 'boolean',
            'is_fragile' => 'boolean',
            'is_bundle' => 'boolean',
            'preorder_enabled' => 'boolean',
            'preorder_ship_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_products')->withPivot('position');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Prix minimum et maximum des variantes actives (listes, tri).
     */
    public function refreshPriceRange(): void
    {
        $prices = $this->variants()->where('is_active', true)->pluck('price');

        $this->forceFill(['price_min' => $prices->min(), 'price_max' => $prices->max()])->saveQuietly();
    }
}
