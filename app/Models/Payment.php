<?php

namespace App\Models;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'order_id', 'kind', 'provider', 'method', 'amount', 'fee', 'currency', 'status', 'provider_reference',
        'idempotency_key', 'note', 'recorded_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['kind' => PaymentKind::class, 'method' => PaymentMethod::class, 'amount' => 'integer', 'paid_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
