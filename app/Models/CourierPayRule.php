<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Rémunération d'un livreur : montant par livraison, ou par zone de livraison ; tentative échouée payée à part.
 */
class CourierPayRule extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'name', 'type', 'amount', 'zone_amounts', 'failed_attempt_amount'];

    protected function casts(): array
    {
        return ['zone_amounts' => 'array', 'amount' => 'integer', 'failed_attempt_amount' => 'integer'];
    }

    public function amountFor(?int $deliveryZoneId): int
    {
        if ($this->type === 'per_zone' && $deliveryZoneId !== null && isset($this->zone_amounts[(string) $deliveryZoneId])) {
            return (int) $this->zone_amounts[(string) $deliveryZoneId];
        }

        return $this->amount;
    }
}
