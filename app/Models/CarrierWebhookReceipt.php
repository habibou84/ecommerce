<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Webhook reçu d'un transporteur : dédoublonnage sur X-Webhook-Id, trace du traitement.
 */
class CarrierWebhookReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = ['connection_id', 'webhook_id', 'event', 'payload', 'received_at', 'processed_at', 'error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
