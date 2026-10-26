<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot de clés d'accès créé dans la console : cartes « Membre fondateur » (offre à vie, numérotées) ou clés à durée.
 */
class AccessKeyBatch extends Model
{
    public const KINDS = ['founder' => 'Membre fondateur (à vie)', 'timed' => 'Durée limitée'];

    protected $fillable = ['kind', 'plan_id', 'months', 'quantity', 'distributor_name', 'distributor_phone', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['months' => 'integer', 'quantity' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function keys(): HasMany
    {
        return $this->hasMany(AccessKey::class, 'batch_id');
    }

    public function isFounder(): bool
    {
        return $this->kind === 'founder';
    }
}
