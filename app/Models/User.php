<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Une personne : un seul compte (numéro de téléphone unique) pour toutes ses boutiques.
 * Ses rôles dépendent de la boutique (spatie/laravel-permission, équipe = store_id).
 */
class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'phone', 'email', 'password', 'phone_verified_at', 'status', 'locale'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'platform_messages_stopped_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'status' => UserStatus::class,
            'password' => 'hashed',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_users')->withPivot('status')->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Appartenance active à la boutique (null si la personne n'en fait pas partie ou y est suspendue).
     */
    public function activeMembershipIn(Store $store): ?StoreUser
    {
        return $this->memberships()->where('store_id', $store->id)->where('status', UserStatus::Active->value)->first();
    }
}
