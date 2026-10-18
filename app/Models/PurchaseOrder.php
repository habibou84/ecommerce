<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bon de commande à un fournisseur.
 */
class PurchaseOrder extends Model
{
    use BelongsToStore;

    public const STATUSES = ['draft' => 'Brouillon', 'sent' => 'Envoyé', 'partial' => 'Reçu en partie', 'received' => 'Reçu', 'cancelled' => 'Annulé'];

    protected $attributes = ['status' => 'draft', 'total_cost' => 0, 'paid_amount' => 0];

    protected $fillable = ['store_id', 'number', 'supplier_id', 'location_id', 'expected_on', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['expected_on' => 'date', 'sent_at' => 'datetime', 'received_at' => 'datetime', 'total_cost' => 'integer', 'paid_amount' => 'integer'];
    }

    public function reference(): string
    {
        return 'BC-'.$this->number;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
