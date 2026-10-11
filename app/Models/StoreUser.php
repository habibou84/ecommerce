<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Appartenance d'une personne à une boutique. Les rôles sont portés par spatie/laravel-permission (équipe = store_id).
 */
class StoreUser extends Model
{
    protected $fillable = ['store_id', 'user_id', 'status', 'invited_by', 'joined_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
