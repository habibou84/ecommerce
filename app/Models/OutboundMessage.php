<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Message envoyé à un client (API WhatsApp) : statut d'envoi, de réception, de lecture.
 */
class OutboundMessage extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'channel', 'to_phone', 'event', 'body', 'related_type', 'related_id', 'status', 'provider_message_id', 'error', 'sent_at', 'delivered_at', 'read_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime'];
    }
}
