<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part d'un abonnement payé revenant à l'entreprise de livraison qui a parrainé la boutique.
 */
class ReferralCommission extends Model
{
    protected $fillable = ['store_id', 'partner', 'subscription_invoice_id', 'invoice_amount', 'rate', 'amount', 'earned_at', 'referral_payout_id'];

    protected function casts(): array
    {
        return ['invoice_amount' => 'integer', 'rate' => 'float', 'amount' => 'integer', 'earned_at' => 'datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(ReferralPayout::class, 'referral_payout_id');
    }
}
