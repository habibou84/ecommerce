<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal d'une expédition (immuable).
 */
class ShipmentEvent extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'shipment_id', 'status', 'raw_status', 'source', 'note', 'user_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['status' => ShipmentStatus::class, 'occurred_at' => 'datetime'];
    }
}
