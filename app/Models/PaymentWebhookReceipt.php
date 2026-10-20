<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookReceipt extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['payment_account_id', 'event_id', 'payload', 'received_at', 'processed_at', 'error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    /** Reçus gardés 90 jours (ils contiennent les données du client) : effacés chaque nuit */
    public function prunable(): Builder
    {
        return static::where('received_at', '<', now()->subDays(90));
    }
}
