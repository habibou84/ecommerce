<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use BelongsToStore, SoftDeletes;

    protected $fillable = [
        'store_id', 'product_id', 'option1_value_id', 'option2_value_id', 'option3_value_id', 'title', 'sku',
        'barcode', 'price', 'compare_at_price', 'cost_price', 'weight_g', 'image_id', 'position', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'cost_price' => 'integer',
            'weight_g' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Contenu du lot, si cette variante est celle d'un lot.
     */
    public function components(): HasMany
    {
        return $this->hasMany(BundleComponent::class, 'bundle_variant_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    public function option1(): BelongsTo
    {
        return $this->belongsTo(ProductOptionValue::class, 'option1_value_id');
    }

    public function option2(): BelongsTo
    {
        return $this->belongsTo(ProductOptionValue::class, 'option2_value_id');
    }

    public function option3(): BelongsTo
    {
        return $this->belongsTo(ProductOptionValue::class, 'option3_value_id');
    }

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'variant_id');
    }

    /**
     * « T-shirt Basic — Rouge / M » (ou le seul nom du produit pour une variante par défaut).
     */
    public function fullTitle(): string
    {
        $product = $this->product;

        return $this->is_default || $product === null ? (string) $product?->title : "{$product->title} — {$this->title}";
    }
}
