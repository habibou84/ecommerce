<?php

namespace App\Models;

use App\Enums\TransferStatus;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transfert entre deux emplacements : la marchandise sort à l'envoi et entre à la réception.
 */
class StockTransfer extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'from_location_id', 'to_location_id', 'status', 'reference', 'shipped_at', 'received_at', 'created_by'];

    protected function casts(): array
    {
        return ['status' => TransferStatus::class, 'shipped_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'transfer_id');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }
}
