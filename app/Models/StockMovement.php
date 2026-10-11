<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Journal immuable des mouvements de stock.
 */
class StockMovement extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = [
        'store_id', 'variant_id', 'location_id', 'type', 'on_hand_change', 'reserved_change', 'on_hand_after',
        'reserved_after', 'unit_cost', 'reference_type', 'reference_id', 'user_id', 'note',
    ];

    protected function casts(): array
    {
        return ['type' => StockMovementType::class];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un mouvement de stock ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Un mouvement de stock ne se supprime pas.'));
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
