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
        'store_id', 'order_id', 'payment_link_id', 'kind', 'payment_account_id', 'provider', 'method', 'amount', 'fee', 'currency',
        'status', 'provider_reference', 'checkout_url', 'raw', 'failure_reason', 'idempotency_key', 'note', 'recorded_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['kind' => PaymentKind::class, 'method' => PaymentMethod::class, 'amount' => 'integer', 'paid_at' => 'datetime', 'raw' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(PaymentLink::class, 'payment_link_id');
    }

    public function refundedAmount(): int
    {
        return (int) Refund::where('payment_id', $this->id)->where('status', 'succeeded')->sum('amount');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
