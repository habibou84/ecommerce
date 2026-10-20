<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Expédition d'une commande (#1042-1) : course chez une entreprise de livraison, avec un statut normalisé
 * quel que soit le transporteur.
 */
class Shipment extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'order_id', 'sequence', 'mode', 'status', 'origin_location_id', 'carrier_connection_id', 'courier_id',
        'carrier_tracking_code', 'carrier_tracking_url', 'carrier_status', 'idempotency_key', 'cod_amount', 'collected_amount',
        'carrier_fee', 'fee_payer', 'weight_g', 'is_fragile', 'delivery_code', 'proof_photo_url', 'proof_photo_path', 'attempts_count',
        'failure_reason', 'rescheduled_to', 'scheduled_date', 'needs_decision', 'return_requested', 'note', 'requested_at', 'picked_up_at', 'delivered_at', 'returned_at', 'cancelled_at',
        'last_synced_at', 'created_by',
    ];

    /** Heure du dernier changement de statut : point de départ des alertes (pas ramassée, problème non traité) */
    protected static function booted(): void
    {
        static::saving(function (Shipment $shipment) {
            if ($shipment->isDirty('status')) {
                $shipment->status_changed_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'is_fragile' => 'boolean',
            'rescheduled_to' => 'date',
            'scheduled_date' => 'date',
            'needs_decision' => 'boolean',
            'return_requested' => 'boolean',
            'requested_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'customer_notified_at' => 'datetime',
            'customer_response_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CarrierConnection::class, 'carrier_connection_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function isOwnCourier(): bool
    {
        return $this->mode === 'own_courier';
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'origin_location_id');
    }

    /**
     * Lignes des reversements du transporteur qui comprennent cette course.
     */
    public function payoutLines(): HasMany
    {
        return $this->hasMany(CarrierPayoutLine::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * « #1042-1 » : référence envoyée au transporteur.
     */
    public function reference(): string
    {
        return $this->order->reference().'-'.$this->sequence;
    }
}
