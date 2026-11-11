<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Services\Live\LiveBilling;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vente en live : articles présentés avec un code court (A1, A2…), réservés pendant le live.
 */
class LiveSession extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'title', 'status', 'hold_minutes', 'stream_url', 'featured_item_id', 'display_mode', 'started_at', 'ended_at', 'created_by', 'pin_ready_at', 'orders_until', 'channel'];

    /** Commandes WhatsApp sans live : ouvertes jusqu'à une heure choisie (ou jusqu'à « Fermer ») */
    public const CHANNEL_WHATSAPP = 'whatsapp';

    /** Durées proposées (heures) ; null = jusqu'à ce que la boutique ferme */
    public const WHATSAPP_HOURS = [24, 72, 168];

    protected $attributes = ['channel' => 'live'];

    protected function casts(): array
    {
        return ['hold_minutes' => 'integer', 'featured_item_id' => 'integer', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'pin_ready_at' => 'datetime',
            'billed_orders' => 'integer', 'billed_offered' => 'integer', 'billed_amount' => 'integer', 'billed_at' => 'datetime', 'orders_until' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(LiveItem::class)->orderBy('position')->orderBy('id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(LiveSessionBill::class)->orderBy('day');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(LiveReservation::class);
    }

    /**
     * Les clients peuvent réserver : live en cours, ou live terminé dont les commandes restent actives (24 h / 48 h).
     */
    public function takesOrders(): bool
    {
        if ($this->isWhatsApp()) {
            return $this->status === 'live' && ($this->orders_until === null || $this->orders_until->isFuture()) && ! app(LiveBilling::class)->paused($this);
        }

        return $this->status === 'live' || ($this->status === 'ended' && $this->orders_until !== null && $this->orders_until->isFuture());
    }

    /** Commandes WhatsApp sans live (pas de TikTok, pas d'écran du live) */
    public function isWhatsApp(): bool
    {
        return $this->channel === self::CHANNEL_WHATSAPP;
    }

    /** Live terminé, commandes encore actives */
    public function extended(): bool
    {
        return ! $this->isWhatsApp() && $this->status === 'ended' && $this->orders_until !== null && $this->orders_until->isFuture();
    }
}
