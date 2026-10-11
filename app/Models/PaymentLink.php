<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lien de paiement envoyé au client (WhatsApp) pour une commande saisie à la main.
 */
class PaymentLink extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'order_id', 'token', 'amount', 'description', 'status', 'expires_at', 'paid_at', 'created_by'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
