<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lien d'accès d'un livreur (voir CourierAccess) : un seul usage, valable TTL_DAYS jours.
 */
class CourierAccessLink extends Model
{
    use BelongsToStore;

    public const TTL_DAYS = 7;

    protected $fillable = ['store_id', 'courier_id', 'token_hash', 'created_by', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Ni utilisé, ni annulé, ni expiré */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
