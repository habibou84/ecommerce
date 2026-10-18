<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de commande : instantané de la variante au moment de la vente.
 */
class OrderItem extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'order_id', 'variant_id', 'product_id', 'title', 'variant_title', 'sku', 'image_path', 'quantity',
        'unit_price', 'compare_at_price', 'unit_cost', 'discount_amount', 'discount_id', 'total', 'weight_g', 'requires_shipping', 'stock_location_id',
        'bundle_components',
    ];

    protected function casts(): array
    {
        return ['requires_shipping' => 'boolean', 'quantity' => 'integer', 'unit_price' => 'integer', 'total' => 'integer', 'bundle_components' => 'array'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    /**
     * Articles dont le stock bouge pour cette ligne : la variante, ou le contenu d'un lot (noté à la commande).
     *
     * @return list<array{variant: ProductVariant, quantity: int}>
     */
    public function stockLines(): array
    {
        if (empty($this->bundle_components)) {
            return $this->variant ? [['variant' => $this->variant, 'quantity' => $this->quantity]] : [];
        }
        $variants = ProductVariant::withTrashed()->with('product')->whereIn('id', array_column($this->bundle_components, 'variant_id'))->get()->keyBy('id');

        return array_values(array_filter(array_map(fn ($c) => $variants->has($c['variant_id'])
            ? ['variant' => $variants[$c['variant_id']], 'quantity' => $c['quantity'] * $this->quantity] : null, $this->bundle_components)));
    }

    public function label(): string
    {
        return $this->variant_title ? "{$this->title} — {$this->variant_title}" : $this->title;
    }
}
