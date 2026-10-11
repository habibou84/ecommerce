<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variante ↔ produit du transporteur (stock déposé dans son entrepôt).
 */
class CarrierProductMapping extends Model
{
    public $timestamps = false;

    protected $fillable = ['connection_id', 'variant_id', 'remote_product_id', 'remote_sku', 'remote_on_hand', 'synced_at'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
