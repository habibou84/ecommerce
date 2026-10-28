<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message WhatsApp d'accueil d'un nouveau commerçant (une étape de la série, pour une boutique).
 */
class OnboardingMessage extends Model
{
    protected $fillable = ['store_id', 'user_id', 'step', 'status', 'detail'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
