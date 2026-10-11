<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce que la boutique doit à un livreur (livraison, tentative, prime) ou retient (avance, retenue).
 */
class CourierEarning extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    public const TYPES = [
        'delivery' => 'Livraison', 'failed_attempt' => 'Tentative', 'bonus' => 'Prime', 'adjustment' => 'Ajustement', 'advance' => 'Avance',
    ];

    protected $fillable = ['store_id', 'courier_id', 'shipment_id', 'type', 'amount', 'payout_id', 'note', 'created_by'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
