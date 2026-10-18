<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réception de marchandise (fournisseur, fabrication…) : entrée en stock avec son coût.
 */
class StockReceipt extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'location_id', 'supplier_id', 'purchase_order_id', 'reference', 'total_cost', 'received_at', 'created_by'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReceiptItem::class, 'receipt_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
