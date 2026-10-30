<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lieu du référentiel commun (ville › commune › quartier). store_id renseigné : lieu ajouté par une boutique,
 * visible d'elle seule.
 */
class Place extends Model
{
    protected $fillable = ['country', 'parent_id', 'type', 'name', 'slug', 'code', 'aliases', 'latitude', 'longitude', 'store_id', 'is_active'];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Lieux communs et lieux propres à la boutique.
     */
    public function scopeVisibleTo(Builder $query, int $storeId): Builder
    {
        return $query->where('is_active', true)->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $storeId));
    }

    /**
     * Code du lieu dans la liste commune (socle), ou à défaut celui du lieu qui le contient : un lieu ajouté par une
     * boutique sous « Angré » donne le code d'Angré.
     */
    public function commonCode(): ?string
    {
        for ($place = $this, $depth = 0; $place !== null && $depth < 5; $place = $place->parent, $depth++) {
            if ($place->code !== null) {
                return $place->code;
            }
        }

        return null;
    }

    /**
     * « Angré, Cocody » (quartier, commune).
     */
    public function label(): string
    {
        return $this->type === 'neighborhood' && $this->parent ? "{$this->name}, {$this->parent->name}" : $this->name;
    }
}
