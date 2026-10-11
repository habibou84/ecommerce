<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierAssignment extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_id', 'shipment_id', 'courier_id', 'status', 'assigned_by', 'assigned_at', 'completed_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }
}
