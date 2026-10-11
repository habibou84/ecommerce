<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhookReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = ['payment_account_id', 'event_id', 'payload', 'received_at', 'processed_at', 'error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
