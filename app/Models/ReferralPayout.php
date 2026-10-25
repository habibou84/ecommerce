<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versement à une entreprise de livraison de sa part des abonnements des boutiques qu'elle a parrainées.
 */
class ReferralPayout extends Model
{
    protected $fillable = ['partner', 'amount', 'method', 'reference', 'note', 'created_by', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime'];
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
