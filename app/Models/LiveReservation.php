<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réservation d'un article pendant un live. reserved : pièce gardée, quartier à donner ; ordered : commande créée ;
 * waiting : liste d'attente ; expired : pas de quartier à temps ; cancelled : annulée par l'équipe.
 */
class LiveReservation extends Model
{
    use BelongsToStore;

    /** Statuts qui gardent une pièce */
    public const HOLDING = ['reserved', 'ordered'];

    protected $fillable = [
        'store_id', 'live_session_id', 'live_item_id', 'source', 'status', 'customer_name', 'customer_phone',
        'shipping_place_id', 'shipping_address', 'order_id', 'expires_at', 'reminded_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LiveItem::class, 'live_item_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
