<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Domaine personnalisé d'une boutique (www.maboutique.ci) : sert uniquement le site public.
 */
class StoreDomain extends Model
{
    protected $fillable = ['store_id', 'hostname', 'is_primary', 'verification_token'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
