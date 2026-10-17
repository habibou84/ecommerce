<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retour ou échange d'articles après livraison.
 */
class OrderReturn extends Model
{
    use BelongsToStore;

    public const STATUSES = ['pending' => 'Article à récupérer', 'received' => 'Article reçu', 'cancelled' => 'Annulé'];

    protected $attributes = ['status' => 'pending', 'restock' => true, 'refunded_amount' => 0];

    protected $fillable = ['store_id', 'order_id', 'number', 'exchange_order_id', 'reason', 'restock', 'returned_value', 'refund_due', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['restock' => 'boolean', 'received_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function reference(): string
    {
        return 'RET-'.$this->number;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function exchangeOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'exchange_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class);
    }
}
