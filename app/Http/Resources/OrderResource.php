<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Services\Catalog\ImageStore;
use App\Services\Shipping\ShipmentPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Jibiat\Socle\PhoneNumber;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $summary = [
            'id' => $this->id,
            'number' => $this->number,
            'reference' => $this->reference(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'fulfillment_status' => $this->fulfillment_status->value,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_phone_display' => PhoneNumber::display($this->customer_phone),
            'delivery_method' => $this->delivery_method,
            'shipping_place_label' => $this->shipping_place_label,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'amount_refunded' => $this->amount_refunded,
            'amount_due' => $this->amountDue(),
            'risk_score' => $this->risk_score,
            'callback_at' => $this->callback_at?->toIso8601String(),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at->toIso8601String(),
        ];

        if (! $this->detailed) {
            return $summary;
        }

        $images = app(ImageStore::class);

        return $summary + [
            'shipping_name' => $this->shipping_name,
            'shipping_phone' => $this->shipping_phone,
            'shipping_phone2' => $this->shipping_phone2,
            'shipping_place_id' => $this->shipping_place_id,
            'shipping_address' => $this->shipping_address,
            'shipping_landmark' => $this->shipping_landmark,
            'delivery_zone' => $this->zone?->only(['id', 'name', 'fee', 'eta_label']),
            'location_id' => $this->location_id,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'time_slot' => $this->time_slot,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'points_used' => $this->points_used,
            'points_discount' => $this->points_discount,
            'referral_discount' => $this->referral_discount,
            'discount_code' => $this->discount_code,
            'shipping_fee' => $this->shipping_fee,
            'cost_total' => $this->cost_total,
            'deposit_required' => $this->deposit_required,
            'customer_note' => $this->customer_note,
            'internal_note' => $this->internal_note,
            'cancel_reason' => $this->cancel_reason?->value,
            'cancel_reason_label' => $this->cancel_reason?->label(),
            'cancel_note' => $this->cancel_note,
            'tracking_url' => $this->trackingUrl(),
            'receipt_url' => $this->receiptUrl(),
            // Lien d'avis du client, une fois la commande livrée
            'review_url' => $this->status === OrderStatus::Completed && filled($this->customer_phone) ? $this->store->url('/avis/'.$this->tracking_token) : null,
            'is_editable' => $this->status->isEditable(),
            'next_statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], $this->status->allowedTransitions()),
            'items' => $this->items->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'variant_id' => $i->variant_id,
                'product_id' => $i->product_id,
                'title' => $i->title,
                'variant_title' => $i->variant_title,
                'sku' => $i->sku,
                'thumb' => $i->image_path ? $images->urls($i->image_path)['thumb'] : null,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
                'total' => $i->total,
                'stock_state' => $i->stock_state,
            ]),
            // Paiements reçus (les tentatives en ligne non abouties ne sont pas listées)
            'payments' => $this->payments->where('status', 'succeeded')->values()->map(fn ($p) => [
                'id' => $p->id,
                'kind_label' => $p->kind->label(),
                'method_label' => $p->method->label(),
                'amount' => $p->amount,
                'reference' => $p->provider_reference,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'recorded_by' => $p->recorder?->name,
            ]),
            'confirmations' => $this->confirmations->map(fn ($c) => [
                'outcome' => $c->outcome->value,
                'outcome_label' => $c->outcome->label(),
                'channel' => $c->channel,
                'note' => $c->note,
                'callback_at' => $c->callback_at?->toIso8601String(),
                'user' => $c->user?->name,
                'created_at' => $c->created_at->toIso8601String(),
            ]),
            'shipments' => $this->shipments->map(fn (Shipment $s) => ShipmentPresenter::present($s)),
            'events' => $this->events->map(fn ($e) => [
                'type' => $e->type,
                'data' => $e->data,
                'user' => $e->user?->name,
                'actor_type' => $e->actor_type,
                'created_at' => $e->created_at->toIso8601String(),
            ]),
        ];
    }

    public static function loadDetail(Order $order): Order
    {
        return $order->load(['items', 'payments.recorder:id,name', 'confirmations.user:id,name', 'events.user:id,name', 'zone', 'store', 'shipments.connection', 'shipments.courier', 'shipments.events', 'shipments.origin']);
    }
}
