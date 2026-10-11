<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantités d'une variante dans un emplacement : cache du journal stock_movements,
 * modifié uniquement par StockKeeper.
 */
class StockLevel extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'variant_id', 'location_id', 'on_hand', 'reserved'];

    protected function casts(): array
    {
        return ['on_hand' => 'integer', 'reserved' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }
}
