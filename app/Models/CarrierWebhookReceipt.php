<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Webhook reçu d'un transporteur : dédoublonnage sur X-Webhook-Id, trace du traitement.
 */
class CarrierWebhookReceipt extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['connection_id', 'webhook_id', 'event', 'payload', 'received_at', 'processed_at', 'error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    /** Reçus gardés 90 jours (noms, téléphones et adresses des clients) : effacés chaque nuit */
    public function prunable(): Builder
    {
        return static::where('received_at', '<', now()->subDays(90));
    }
}
