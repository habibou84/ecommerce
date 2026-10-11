<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Zone de livraison de la boutique : un ensemble de lieux, des frais et un délai annoncé.
 */
class DeliveryZone extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'name', 'type', 'fee', 'express_fee', 'free_above', 'eta_label', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'fee' => 'integer', 'free_above' => 'integer'];
    }

    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class, 'delivery_zone_places')->withPivot('store_id');
    }

    /**
     * Frais pour un sous-total donné (livraison offerte au-delà du seuil).
     */
    public function feeFor(int $subtotal): int
    {
        return $this->free_above !== null && $subtotal >= $this->free_above ? 0 : $this->fee;
    }
}
