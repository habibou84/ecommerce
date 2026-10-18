<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versement déclaré par le livreur, que la boutique confirme (il devient un versement) ou refuse.
 */
class CourierRemittanceDeclaration extends Model
{
    use BelongsToStore;

    public const METHODS = ['cash' => 'Espèces à la caisse', 'wave' => 'Wave', 'orange_money' => 'Orange Money', 'mtn_momo' => 'MTN MoMo', 'moov_money' => 'Moov Money'];

    protected $fillable = ['store_id', 'courier_id', 'amount', 'method', 'reference', 'note', 'proof_path', 'held_amount', 'status'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'held_amount' => 'integer', 'decided_at' => 'datetime'];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(CourierRemittance::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
