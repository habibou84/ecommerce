<?php

namespace App\Services\Orders;

use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\PaymentDesk;
use App\Services\Stock\StockKeeper;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\Money;

/**
 * Retours et échanges après livraison. Le client rend des articles : ils reviennent en stock à leur réception
 * (sauf article abîmé) et leur valeur (prix payé) est remboursée. Pour un échange (autre taille, autre couleur),
 * une commande d'échange est créée pour les articles de remplacement, déjà confirmée, avec une remise égale à la
 * valeur rendue : elle se livre comme une autre commande et le livreur reprend l'article rendu. Si le remplacement
 * coûte plus, le client paie la différence à la livraison ; s'il coûte moins, la différence lui est remboursée.
 */
class ReturnDesk
{
    public function __construct(
        private readonly OrderDesk $orders,
        private readonly StockKeeper $stock,
        private readonly PaymentDesk $payments,
    ) {}

    /**
     * @param  array{items: list<array{order_item_id: int, quantity: int, replacement_variant_id?: ?int, replacement_quantity?: ?int}>, reason?: ?string, restock?: bool, note?: ?string, exchange_shipping_fee?: ?int}  $data
     */
    public function create(Order $order, array $data, User $actor): OrderReturn
    {
        if ($order->status !== OrderStatus::Completed) {
            throw new BusinessRuleException('Un retour ou un échange se fait sur une commande livrée.');
        }

        return DB::transaction(function () use ($order, $data, $actor) {
            Order::forStore($order->store_id)->whereKey($order->id)->lockForUpdate()->first();
            $order->load('items');
            $already = OrderReturnItem::whereHas('orderReturn', fn ($q) => $q->where('order_id', $order->id)->where('status', '!=', 'cancelled'))
                ->selectRaw('order_item_id, SUM(quantity) AS qty')->groupBy('order_item_id')->pluck('qty', 'order_item_id');

            // Remise saisie sur la commande (sans détail par article) : répartie au prorata
            $allocated = (int) $order->items->sum('discount_amount');
            $orderDiscount = $allocated === 0 && $order->subtotal > 0 ? $order->discount_total / $order->subtotal : 0;

            $lines = [];
            $replacements = [];
            $value = 0;
            foreach ($data['items'] as $row) {
                /** @var OrderItem|null $item */
                $item = $order->items->firstWhere('id', (int) $row['order_item_id']);
                $quantity = (int) $row['quantity'];
                if ($item === null) {
                    throw new BusinessRuleException('Article introuvable dans cette commande.', 'items');
                }
                $left = $item->quantity - (int) ($already[$item->id] ?? 0);
                if ($quantity < 1 || $quantity > $left) {
                    throw new BusinessRuleException("« {$item->label()} » : {$left} article(s) au plus peuvent être rendus.", 'items');
                }
                $unit = (int) floor(((int) $item->total - (int) $item->discount_amount) * (1 - $orderDiscount) / max(1, $item->quantity));
                $value += $unit * $quantity;

                $replacement = null;
                if (! empty($row['replacement_variant_id'])) {
                    $replacement = ProductVariant::forStore($order->store_id)->where('is_active', true)->find((int) $row['replacement_variant_id'])
                        ?? throw new BusinessRuleException('Article de remplacement introuvable.', 'items');
                    $replacements[] = ['variant_id' => $replacement->id, 'quantity' => max(1, (int) ($row['replacement_quantity'] ?? $quantity))];
                }
                $lines[] = [
                    'order_item_id' => $item->id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $quantity,
                    'unit_value' => $unit,
                    'replacement_variant_id' => $replacement?->id,
                    'replacement_quantity' => $replacement ? max(1, (int) ($row['replacement_quantity'] ?? $quantity)) : 0,
                ];
            }
            if ($lines === []) {
                throw new BusinessRuleException('Choisissez au moins un article rendu.', 'items');
            }

            Store::whereKey($order->store_id)->lockForUpdate()->first();
            $number = (int) OrderReturn::forStore($order->store_id)->max('number') + 1;
            $return = OrderReturn::create([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'number' => $number,
                'reason' => $data['reason'] ?? null,
                'restock' => $data['restock'] ?? true,
                'returned_value' => $value,
                'refund_due' => $value,
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
            ]);
            $return->items()->createMany($lines);

            if ($replacements !== []) {
                $exchange = $this->exchangeOrder($order, $return, $replacements, $value, $data['exchange_shipping_fee'] ?? 0, $actor);
                $return->forceFill([
                    'exchange_order_id' => $exchange->id,
                    'refund_due' => max(0, $value - $exchange->subtotal),
                ])->save();
            }

            $this->orders->log($order, 'return_created', ['return' => $return->reference(), 'value' => $value, 'exchange' => $return->exchange_order_id !== null], $actor);

            return $return->load('items');
        });
    }

    /**
     * L'article rendu est revenu à la boutique : remis en stock et, au choix, remboursé.
     */
    public function receive(OrderReturn $return, User $actor, bool $refund = true): OrderReturn
    {
        return DB::transaction(function () use ($return, $actor, $refund) {
            $return = OrderReturn::forStore($return->store_id)->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== 'pending') {
                throw new BusinessRuleException('Ce retour est déjà traité.');
            }
            $return->load('items.orderItem', 'order');
            $order = $return->order;

            if ($return->restock) {
                foreach ($return->items as $line) {
                    $location = $line->orderItem?->location ?? $order->location ?? $this->stock->defaultLocation($order->store);
                    // Lot : chaque article du lot revient en stock
                    $perUnit = $line->orderItem ? array_map(fn ($l) => ['variant' => $l['variant'], 'quantity' => intdiv($l['quantity'], max(1, $line->orderItem->quantity))], $line->orderItem->stockLines()) : [];
                    foreach ($perUnit as $entry) {
                        if ($entry['variant']->product?->track_inventory) {
                            $this->stock->returnToStock($actor, $entry['variant'], $location, $entry['quantity'] * $line->quantity, $return, 'Retour '.$return->reference());
                        }
                    }
                }
            }

            $refunded = 0;
            if ($refund && $return->refund_due > 0) {
                $refunded = min($return->refund_due, $order->amount_paid - $order->amount_refunded);
                if ($refunded > 0) {
                    $this->payments->refund($order, $refunded, 'Retour '.$return->reference(), $actor);
                }
            }

            $return->forceFill(['status' => 'received', 'received_at' => now(), 'received_by' => $actor->id, 'refunded_amount' => $refunded])->save();
            $this->orders->log($order, 'return_received', ['return' => $return->reference(), 'refunded' => $refunded, 'restocked' => $return->restock], $actor);

            return $return;
        });
    }

    public function cancel(OrderReturn $return, User $actor): OrderReturn
    {
        return DB::transaction(function () use ($return, $actor) {
            $return = OrderReturn::forStore($return->store_id)->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== 'pending') {
                throw new BusinessRuleException('Seul un retour en attente peut être annulé.');
            }
            $exchange = $return->exchangeOrder;
            if ($exchange && $exchange->status !== OrderStatus::Cancelled) {
                if (! $exchange->status->canTransitionTo(OrderStatus::Cancelled)) {
                    throw new BusinessRuleException('La commande d\'échange '.$exchange->reference().' est déjà en route : annulez d\'abord sa livraison.');
                }
                $this->orders->cancel($exchange, CancelReason::CustomerRequest, 'Échange '.$return->reference().' annulé', $actor);
            }
            $return->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            $this->orders->log($return->order, 'return_cancelled', ['return' => $return->reference()], $actor);

            return $return;
        });
    }

    /**
     * Commande d'échange : articles de remplacement, livrés à la même adresse, valeur rendue en remise.
     *
     * @param  list<array{variant_id: int, quantity: int}>  $items
     */
    private function exchangeOrder(Order $order, OrderReturn $return, array $items, int $credit, int $shippingFee, User $actor): Order
    {
        $taken = $return->items()->with('orderItem')->get()->map(fn ($l) => "{$l->quantity} × {$l->orderItem->label()}")->implode(', ');
        $exchange = $this->orders->create($order->store, [
            'source' => $order->source->value,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'delivery_method' => $order->delivery_method,
            'shipping_name' => $order->shipping_name,
            'shipping_phone' => $order->shipping_phone,
            'shipping_phone2' => $order->shipping_phone2,
            'shipping_place_id' => $order->shipping_place_id,
            'shipping_address' => $order->shipping_address,
            'shipping_landmark' => $order->shipping_landmark,
            'pickup_location_id' => $order->pickup_location_id,
            'location_id' => $order->location_id,
            'internal_note' => "Échange {$return->reference()} de la commande {$order->reference()} : reprendre {$taken}",
            'shipping_fee' => $shippingFee,
            'discount_total' => $credit,
            'confirmed' => true,
            'items' => $items,
        ], $actor);

        return $exchange->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(OrderReturn $return): array
    {
        $return->loadMissing('items.orderItem', 'items.replacement.product', 'exchangeOrder', 'order');

        return [
            'id' => $return->id,
            'reference' => $return->reference(),
            'order_id' => $return->order_id,
            'order_reference' => $return->order?->reference(),
            'customer_name' => $return->order?->customer_name,
            'status' => $return->status,
            'status_label' => OrderReturn::STATUSES[$return->status] ?? $return->status,
            'reason' => $return->reason,
            'restock' => $return->restock,
            'returned_value' => $return->returned_value,
            'refund_due' => $return->refund_due,
            'refunded_amount' => $return->refunded_amount,
            'note' => $return->note,
            'items' => $return->items->map(fn (OrderReturnItem $l) => [
                'label' => $l->orderItem?->label(),
                'quantity' => $l->quantity,
                'unit_value' => $l->unit_value,
                'replacement' => $l->replacement ? trim($l->replacement->product?->title.($l->replacement->title && $l->replacement->title !== 'Default' ? ' — '.$l->replacement->title : '')) : null,
                'replacement_quantity' => $l->replacement_quantity,
            ]),
            'exchange_order' => $return->exchangeOrder ? [
                'id' => $return->exchangeOrder->id,
                'reference' => $return->exchangeOrder->reference(),
                'status_label' => $return->exchangeOrder->status->label(),
                'amount_due' => $return->exchangeOrder->amountDue(),
            ] : null,
            'summary' => $return->refund_due > 0 ? 'À rembourser : '.Money::format($return->refund_due) : null,
            'created_at' => $return->created_at->toIso8601String(),
            'received_at' => $return->received_at?->toIso8601String(),
        ];
    }
}
