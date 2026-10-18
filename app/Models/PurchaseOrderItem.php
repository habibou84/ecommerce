<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['purchase_order_id', 'variant_id', 'quantity', 'received_quantity', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'received_quantity' => 'integer', 'unit_cost' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
