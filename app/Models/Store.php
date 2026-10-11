<?php

namespace App\Models;

use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_id', 'slug', 'name', 'tagline', 'country', 'currency', 'timezone', 'locale',
        'phone', 'whatsapp_phone', 'email', 'address', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'require_order_confirmation' => 'boolean',
            'require_delivery_code' => 'boolean',
            'allow_backorder' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_users')->withPivot('status')->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(StoreInvitation::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(StoreDomain::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->latestOfMany();
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Adresse sur le sous-domaine de la boutique : url('/gestion') → https://{slug}.jibiat-marchand.com/gestion
     */
    public function url(string $path = '/'): string
    {
        return Tenancy::storeUrl($this->slug, $path);
    }
}
