<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transfert de stock entre deux boutiques de la même adresse (pas de portée de boutique : il appartient aux deux).
 */
class StoreTransfer extends Model
{
    protected $fillable = ['from_store_id', 'to_store_id', 'from_location_id', 'to_location_id', 'lines', 'quantity', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'quantity' => 'integer'];
    }

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
