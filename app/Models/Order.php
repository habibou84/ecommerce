<?php

namespace App\Models;

use App\Enums\CancelReason;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'customer_id', 'source', 'external_reference', 'customer_name', 'customer_phone', 'delivery_method',
        'shipping_name', 'shipping_phone', 'shipping_phone2', 'shipping_place_id', 'shipping_place_label', 'shipping_address',
        'shipping_landmark', 'shipping_latitude', 'shipping_longitude', 'delivery_zone_id', 'pickup_location_id', 'location_id',
        'scheduled_date', 'time_slot', 'discount_total', 'shipping_fee', 'deposit_required', 'discount_code', 'customer_note',
        'internal_note', 'utm',
    ];

    protected function casts(): array
    {
        return [
            'source' => OrderSource::class,
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'cancel_reason' => CancelReason::class,
            'scheduled_date' => 'date',
            'utm' => 'array',
            'confirmed_at' => 'datetime',
            'callback_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderByDesc('id');
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(OrderConfirmation::class)->orderByDesc('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'shipping_place_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * « #1042 »
     */
    public function reference(): string
    {
        return ($this->store?->order_prefix ?? '#').$this->number;
    }

    public function amountDue(): int
    {
        return max(0, $this->total - $this->amount_paid + $this->amount_refunded);
    }

    public function trackingUrl(): string
    {
        return $this->store->url('/suivi/'.$this->tracking_token);
    }
}
