<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clé d'accès d'un lot : un code (dans le QR de la carte), activé une seule fois par un espace marchand.
 */
class AccessKey extends Model
{
    protected $fillable = ['batch_id', 'number', 'code', 'store_id', 'activated_at', 'expires_at', 'paused_at', 'warned_at', 'ended_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'paused_at' => 'datetime',
            'warned_at' => 'datetime',
            'ended_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AccessKeyBatch::class, 'batch_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Code affiché sur la carte : deux groupes de quatre caractères.
     */
    public function displayCode(): string
    {
        return substr($this->code, 0, 4).'-'.substr($this->code, 4);
    }
}
