<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'order_id', 'payment_id', 'amount', 'method', 'reason', 'status', 'provider_reference', 'refunded_at', 'created_by'];

    protected function casts(): array
    {
        return ['refunded_at' => 'datetime'];
    }
}
