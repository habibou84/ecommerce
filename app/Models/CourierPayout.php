<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Relevé de paie d'un livreur : regroupe ses gains non payés jusqu'à une date.
 */
class CourierPayout extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'courier_id', 'period_start', 'period_end', 'total', 'status', 'method', 'reference', 'paid_at', 'paid_by', 'created_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'paid_at' => 'datetime'];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(CourierEarning::class, 'payout_id');
    }
}
