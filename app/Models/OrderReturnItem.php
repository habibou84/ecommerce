<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReturnItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_return_id', 'order_item_id', 'variant_id', 'quantity', 'unit_value', 'replacement_variant_id', 'replacement_quantity'];

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'replacement_variant_id');
    }
}
