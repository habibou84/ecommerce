<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facture d'abonnement d'une boutique, payée sur le compte de la plateforme (ou offerte par la plateforme).
 */
class SubscriptionInvoice extends Model
{
    use BelongsToStore;

    public const STATUSES = ['pending' => 'En attente', 'paid' => 'Payée', 'failed' => 'Échouée', 'cancelled' => 'Annulée', 'offered' => 'Offerte'];

    protected $fillable = [
        'store_id', 'subscription_id', 'plan_id', 'number', 'billing_cycle', 'amount', 'status', 'period_start', 'period_end',
        'provider', 'method', 'idempotency_key', 'provider_reference', 'checkout_url', 'raw', 'created_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['raw' => 'array', 'amount' => 'integer', 'period_start' => 'datetime', 'period_end' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
