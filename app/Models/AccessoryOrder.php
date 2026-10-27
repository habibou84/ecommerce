<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commande de matériel d'un espace marchand à la plateforme.
 */
class AccessoryOrder extends Model
{
    public const STATUSES = [
        'pending' => 'À confirmer',
        'confirmed' => 'Confirmée',
        'preparing' => 'En préparation',
        'shipping' => 'En livraison',
        'delivered' => 'Livrée',
        'cancelled' => 'Annulée',
    ];

    public const PAYMENT_METHODS = ['wave' => 'Wave', 'cod' => 'Paiement à la livraison'];

    public const PAYMENT_STATUSES = ['unpaid' => 'Non payée', 'claimed' => 'Paiement annoncé', 'paid' => 'Payée'];

    protected $fillable = [
        'store_id', 'user_id', 'status', 'items', 'subtotal', 'delivery', 'delivery_fee', 'total', 'earnings',
        'payment_method', 'payment_status', 'payer_phone', 'contact_name', 'contact_phone', 'address', 'notes',
        'claimed_at', 'paid_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'delivery' => 'array',
            'subtotal' => 'integer',
            'delivery_fee' => 'integer',
            'total' => 'integer',
            'earnings' => 'integer',
            'claimed_at' => 'datetime',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['delivered', 'cancelled'], true);
    }
}
