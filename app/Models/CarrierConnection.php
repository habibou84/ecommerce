<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Entreprise de livraison de la boutique : connectée par l'API de Delivery App, ou sans API (« manuel » :
 * la course est demandée par WhatsApp et suivie à la main).
 */
class CarrierConnection extends Model
{
    use BelongsToStore;

    public const DELIVERY_APP = 'delivery_app';

    public const MANUAL = 'manual';

    protected $fillable = [
        'store_id', 'driver', 'name', 'base_url', 'api_key', 'api_key_prefix', 'scopes', 'remote_account', 'webhook_uuid',
        'webhook_secret', 'remote_webhook_id', 'contact_phone', 'settings', 'status', 'last_error', 'last_synced_at', 'connected_at',
    ];

    protected $hidden = ['api_key', 'webhook_secret'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'scopes' => 'array',
            'remote_account' => 'array',
            'settings' => 'array',
            'last_synced_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->webhook_uuid ??= (string) Str::uuid());
    }

    public function zones(): HasMany
    {
        return $this->hasMany(CarrierZone::class, 'connection_id');
    }

    public function zoneMappings(): HasMany
    {
        return $this->hasMany(CarrierZoneMapping::class, 'connection_id');
    }

    public function isApi(): bool
    {
        return $this->driver === self::DELIVERY_APP;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
