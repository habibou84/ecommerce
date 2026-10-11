<?php

namespace App\Models\Concerns;

use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * À utiliser sur tous les modèles métier portant une colonne store_id.
 */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope(new StoreScope);

        static::creating(function ($model) {
            if ($model->store_id === null && ($store = app(Tenancy::class)->store()) !== null) {
                $model->store_id = $store->id;
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->withoutGlobalScope(StoreScope::class)->where($this->qualifyColumn('store_id'), $storeId);
    }
}
