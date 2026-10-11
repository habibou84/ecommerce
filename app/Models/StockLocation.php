<?php

namespace App\Models;

use App\Enums\StockLocationType;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLocation extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'name', 'type', 'address', 'landmark', 'phone', 'is_pickup_point', 'fulfills_online_orders', 'priority', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => StockLocationType::class,
            'is_pickup_point' => 'boolean',
            'fulfills_online_orders' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'location_id');
    }
}
