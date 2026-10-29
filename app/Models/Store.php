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
use Illuminate\Support\Collection;

class Store extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug', 'name', 'tagline', 'country', 'currency', 'timezone', 'locale',
        'phone', 'whatsapp_phone', 'email', 'address',
    ];

    // Secrets jamais renvoyés quand la boutique est transformée en tableau ou en JSON
    protected $hidden = ['meta_capi_token'];

    // Valeurs par défaut des colonnes, connues avant le rechargement depuis la base
    protected $attributes = [
        'status' => 'trial',
        'country' => 'CI',
        'currency' => 'XOF',
        'timezone' => 'Africa/Abidjan',
        'order_prefix' => '#',
        'next_order_number' => 1001,
        'require_order_confirmation' => true,
        'storefront_enabled' => false,
        'online_payment' => 'off',
        'assignment_mode' => 'suggest',
        'require_delivery_code' => false,
        'courier_tracking' => false,
        'default_fee_payer' => 'customer',
        'allow_backorder' => false,
        'low_stock_threshold' => 3,
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'require_order_confirmation' => 'boolean',
            'require_delivery_code' => 'boolean',
            'courier_tracking' => 'boolean',
            'allow_backorder' => 'boolean',
            'storefront_enabled' => 'boolean',
            'whatsapp_qr_enabled' => 'boolean',
            'closed_at' => 'datetime',
            'free_shipping_above' => 'integer',
            'billing_notices' => 'array',
            'ai_credits' => 'integer',
            'marketing' => 'array',
            'delivery_calendar' => 'array',
            'alert_settings' => 'array',
            'setup_guide' => 'array',
            'loyalty_settings' => 'array',
            'message_texts' => 'array',
            'auto_replies' => 'array',
            'meta_capi_token' => 'encrypted',
        ];
    }

    /**
     * Identifiant public d'un outil de suivi (meta_pixel_id, tiktok_pixel_id, ga4_id).
     */
    public function marketingId(string $key): ?string
    {
        return filled($this->marketing[$key] ?? null) ? (string) $this->marketing[$key] : null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Boutique dont l'abonnement Business couvre celle-ci.
     */
    public function billingStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'billing_store_id');
    }

    /**
     * Offre « gratuit à vie » donnée depuis la console.
     */
    public function freePlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'free_plan_id');
    }

    /**
     * Boutique dont l'adresse héberge celle-ci (offre Business).
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'host_store_id');
    }

    /**
     * Boutiques hébergées à l'adresse de celle-ci.
     */
    public function hostedStores(): HasMany
    {
        return $this->hasMany(Store::class, 'host_store_id');
    }

    /**
     * Boutiques couvertes par l'abonnement de celle-ci.
     */
    public function coveredStores(): HasMany
    {
        return $this->hasMany(Store::class, 'billing_store_id');
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

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
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

    public function activeTheme(): BelongsTo
    {
        return $this->belongsTo(StoreTheme::class, 'active_theme_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Adresse sur le sous-domaine de la boutique : url('/gestion') → https://{slug}.jibiat-marchand.com/gestion
     */
    /**
     * Adresse d'une page de la boutique. Une boutique hébergée par une autre a sa boutique en ligne sous
     * {adresse}/{path} ; sa gestion et l'application livreur sont celles de l'adresse, boutique choisie
     * par le paramètre « boutique ».
     */
    public function url(string $path = '/'): string
    {
        if ($this->host_store_id !== null && ($host = $this->host) !== null) {
            $path = '/'.ltrim($path, '/');
            if (preg_match('#^/(gestion|livreur|auth|invitation)(/|\?|$)#', $path)) {
                return $host->url($path.(str_contains($path, '?') ? '&' : '?').'boutique='.$this->id);
            }

            return $host->url('/'.$this->path.($path === '/' ? '' : $path));
        }

        return Tenancy::storeUrl($this->slug, $path);
    }

    /**
     * Boutiques servies par la même adresse : la boutique hôte puis ses boutiques hébergées.
     *
     * @return Collection<int, Store>
     */
    public function addressStores(): Collection
    {
        $host = $this->host ?? $this;

        return collect([$host])->concat($host->hostedStores()->orderBy('id')->get());
    }

    public function isHosted(): bool
    {
        return $this->host_store_id !== null;
    }
}
