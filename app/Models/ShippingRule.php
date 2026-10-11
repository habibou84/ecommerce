<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Règle d'affectation automatique : conditions sur la commande → livreur de la boutique, transporteur, le moins cher…
 */
class ShippingRule extends Model
{
    use BelongsToStore;

    public const ACTIONS = [
        'own_courier' => 'Mes livreurs',
        'carrier' => 'Ce transporteur',
        'cheapest' => 'Le moins cher',
        'fastest' => 'Le plus rapide',
        'manual' => 'Choisir à la main',
    ];

    protected $fillable = ['store_id', 'name', 'priority', 'conditions', 'action', 'carrier_connection_id', 'is_active'];

    protected $attributes = ['is_active' => true, 'priority' => 1];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'is_active' => 'boolean', 'priority' => 'integer'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CarrierConnection::class, 'carrier_connection_id');
    }
}
