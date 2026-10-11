<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reversement d'un transporteur à la boutique, rapproché des expéditions livrées.
 */
class CarrierPayout extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'connection_id', 'remote_id', 'amount', 'total_collected', 'total_fees', 'method', 'transaction_ref',
        'paid_at', 'reconciliation_status', 'difference', 'raw',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'raw' => 'array'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CarrierConnection::class, 'connection_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CarrierPayoutLine::class);
    }
}
