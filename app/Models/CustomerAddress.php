<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'customer_id', 'label', 'recipient_name', 'phone', 'place_id', 'address_line', 'landmark', 'latitude', 'longitude', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}
