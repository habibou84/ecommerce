<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remise des retours par un livreur de Jibiat (contrat socle 1.14) : le livreur rapporte des colis, le marchand
 * confirme qu'il a tout reçu ou dit qu'il en manque. Les commandes passent « Retournée » (et le stock revient)
 * par le statut des courses, une fois la remise confirmée.
 */
class CarrierReturnHandover extends Model
{
    use BelongsToStore;

    /** Le marchand peut encore confirmer ou contester (expired : QR code périmé, la remise reste confirmable) */
    public const OPEN = ['pending', 'expired', 'unconfirmed'];

    protected $fillable = [
        'store_id', 'connection_id', 'reference', 'status', 'courier_name', 'received_by_name', 'parcels',
        'expires_at', 'handed_at', 'confirmed_at', 'confirmed_via', 'disputed_at',
    ];

    protected function casts(): array
    {
        return [
            'parcels' => 'array',
            'expires_at' => 'datetime',
            'handed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'disputed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CarrierConnection::class, 'connection_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
