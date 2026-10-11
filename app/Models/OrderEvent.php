<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Journal immuable d'une commande.
 */
class OrderEvent extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'order_id', 'type', 'data', 'actor_type', 'user_id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal d\'une commande ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Le journal d\'une commande ne se supprime pas.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
