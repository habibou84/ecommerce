<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Rémunération d'un livreur : montant par livraison, ou par zone de livraison ; expédition (dépôt à la gare) et
 * tentative échouée payées à part.
 */
class CourierPayRule extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'name', 'type', 'amount', 'zone_amounts', 'expedition_amount', 'failed_attempt_amount'];

    protected function casts(): array
    {
        return ['zone_amounts' => 'array', 'amount' => 'integer', 'expedition_amount' => 'integer', 'failed_attempt_amount' => 'integer'];
    }

    public function amountFor(?int $deliveryZoneId, bool $expedition = false): int
    {
        if ($expedition) {
            return $this->expedition_amount ?? $this->amount;
        }
        if ($this->type === 'per_zone' && $deliveryZoneId !== null && isset($this->zone_amounts[(string) $deliveryZoneId])) {
            return (int) $this->zone_amounts[(string) $deliveryZoneId];
        }

        return $this->amount;
    }
}
