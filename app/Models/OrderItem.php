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
        'unit_price', 'compare_at_price', 'unit_cost', 'discount_amount', 'total', 'weight_g', 'requires_shipping', 'stock_location_id',
    ];

    protected function casts(): array
    {
        return ['requires_shipping' => 'boolean', 'quantity' => 'integer', 'unit_price' => 'integer', 'total' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function label(): string
    {
        return $this->variant_title ? "{$this->title} — {$this->variant_title}" : $this->title;
    }
}
