<?php

namespace App\Models;

use App\Enums\CountStatus;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventaire d'un emplacement : on compte, puis la quantité comptée remplace la quantité en stock.
 */
class InventoryCount extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'location_id', 'status', 'note', 'started_by', 'completed_by', 'completed_at'];

    protected function casts(): array
    {
        return ['status' => CountStatus::class, 'completed_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountItem::class, 'count_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }
}
