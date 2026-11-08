<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conversation d'inscription par WhatsApp d'un numéro (une ligne par numéro, reprise à chaque « Inscription »).
 */
class WhatsappSignup extends Model
{
    protected $fillable = ['phone', 'step', 'data', 'reached', 'store_id', 'last_inbound_at', 'reminded_at', 'paused_until', 'completed_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'reached' => 'array',
            'last_inbound_at' => 'datetime',
            'reminded_at' => 'datetime',
            'paused_until' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function paused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }
}
