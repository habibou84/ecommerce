<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vente en live : articles présentés avec un code court (A1, A2…), réservés pendant le live.
 */
class LiveSession extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'title', 'status', 'hold_minutes', 'started_at', 'ended_at', 'created_by'];

    protected function casts(): array
    {
        return ['hold_minutes' => 'integer', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(LiveItem::class)->orderBy('position')->orderBy('id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(LiveReservation::class);
    }
}
