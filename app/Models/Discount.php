<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Jibiat\Socle\Money;

/**
 * Code promo (code renseigné) ou vente flash (remise automatique sur les produits visés, sans code).
 */
class Discount extends Model
{
    use BelongsToStore;

    public const TYPES = ['percentage' => 'Pourcentage', 'fixed_amount' => 'Montant', 'free_shipping' => 'Livraison offerte'];

    protected $fillable = [
        'store_id', 'code', 'title', 'type', 'value', 'applies_to', 'target_ids', 'min_subtotal', 'usage_limit',
        'per_customer_limit', 'starts_at', 'ends_at', 'is_active',
    ];

    protected $attributes = ['applies_to' => 'all', 'used_count' => 0, 'is_active' => true, 'value' => 0];

    protected function casts(): array
    {
        return [
            'target_ids' => 'array',
            'value' => 'integer',
            'min_subtotal' => 'integer',
            'usage_limit' => 'integer',
            'per_customer_limit' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(DiscountUsage::class);
    }

    public function isAutomatic(): bool
    {
        return $this->code === null;
    }

    /**
     * Actives en ce moment (dates comprises).
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isRunning(): bool
    {
        return $this->is_active && ($this->starts_at === null || $this->starts_at->isPast()) && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /**
     * Le produit est-il visé ? ($collectionIds : collections du produit)
     *
     * @param  list<int>  $collectionIds
     */
    public function targets(int $productId, array $collectionIds): bool
    {
        $ids = array_map('intval', $this->target_ids ?? []);

        return match ($this->applies_to) {
            'products' => in_array($productId, $ids, true),
            'collections' => array_intersect($ids, $collectionIds) !== [],
            default => true,
        };
    }

    /**
     * Prix unitaire après la remise automatique (vente flash).
     */
    public function reducedPrice(int $price): int
    {
        return match ($this->type) {
            'percentage' => max(0, (int) round($price * (100 - min(100, $this->value)) / 100)),
            'fixed_amount' => max(0, $price - $this->value),
            default => $price,
        };
    }

    public function summary(): string
    {
        return match ($this->type) {
            'percentage' => "−{$this->value} %",
            'fixed_amount' => '−'.Money::format($this->value),
            default => 'Livraison offerte',
        };
    }
}
