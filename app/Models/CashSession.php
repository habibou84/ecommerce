<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Session de caisse : du fond de caisse à l'ouverture jusqu'au comptage de la clôture.
 */
class CashSession extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'opening_float', 'opened_at', 'opened_by', 'closed_at', 'closed_by', 'expected_cash', 'counted_cash', 'difference', 'note'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
