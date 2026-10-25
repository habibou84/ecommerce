<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Article d'un live : variante, code court, prix du live et pièces proposées.
 */
class LiveItem extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'live_session_id', 'variant_id', 'code', 'price', 'quantity', 'position'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'quantity' => 'integer', 'position' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(LiveReservation::class);
    }
}
