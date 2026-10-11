<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Montant encaissé par un livreur auprès d'un client. En espèces, il est détenu par le livreur jusqu'à son versement.
 */
class CashCollection extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_id', 'shipment_id', 'courier_id', 'payment_id', 'amount', 'method', 'remittance_id', 'collected_at'];

    protected function casts(): array
    {
        return ['collected_at' => 'datetime', 'amount' => 'integer'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
