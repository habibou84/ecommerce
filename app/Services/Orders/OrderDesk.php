<?php

namespace App\Services\Orders;

use App\Enums\CancelReason;
use App\Enums\ConfirmationOutcome;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Place;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\User;
use App\Notifications\OrderPlaced;
use App\Services\Accounts\TeamNotifier;
use App\Services\Customers\CustomerBook;
use App\Services\Places\Places;
use App\Services\Stock\StockKeeper;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Toutes les opérations sur les commandes passent par ici : création, modification, changements de statut
 * (avec leurs effets sur le stock), paiements, tentatives de confirmation. Chaque opération s'inscrit au journal.
 *
 * Stock : les articles sont réservés à la création, sortent du stock à l'expédition (ou à la livraison directe),
 * sont libérés à l'annulation et reviennent en stock au retour (si on le demande).
 */
class OrderDesk
{
    private const CONTACT_FIELDS = [
        'customer_name', 'delivery_method', 'shipping_name', 'shipping_phone', 'shipping_phone2', 'shipping_place_id',
        'shipping_address', 'shipping_landmark', 'shipping_latitude', 'shipping_longitude', 'pickup_location_id',
        'scheduled_date', 'time_slot', 'customer_note', 'internal_note', 'external_reference',
    ];

    public function __construct(
        private readonly StockKeeper $stock,
        private readonly CustomerBook $customers,
        private readonly Places $places,
        private readonly TeamNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $data  champs validés (OrderRequest), items : [{variant_id|title, quantity, unit_price?}]
     */
    public function create(Store $store, array $data, ?User $actor, string $actorType = 'user'): Order
    {
        $order = DB::transaction(function () use ($store, $data, $actor, $actorType) {
            $customer = $this->customers->findOrCreate($store, $data['customer_phone'], $data['customer_name'] ?? null, ['source' => $data['source']]);

            $order = new Order(['store_id' => $store->id]);
            $order->fill(Arr::only($data, self::CONTACT_FIELDS));
            $order->forceFill([
                'store_id' => $store->id,
                'number' => $this->nextNumber($store),
                'customer_id' => $customer->id,
                'customer_name' => $data['customer_name'] ?? $customer->name,
                'customer_phone' => $data['customer_phone'],
                'source' => OrderSource::from($data['source']),
                'utm' => $data['utm'] ?? null,
                'shipping_name' => $data['shipping_name'] ?? $data['customer_name'] ?? $customer->name,
                'shipping_phone' => $data['shipping_phone'] ?? $data['customer_phone'],
                'location_id' => $this->location($store, $data['location_id'] ?? null)->id,
                'discount_total' => (int) ($data['discount_total'] ?? 0),
                'deposit_required' => $data['deposit_required'] ?? null,
                'status' => OrderStatus::PendingConfirmation,
                'payment_status' => PaymentStatus::Unpaid,
                'fulfillment_status' => FulfillmentStatus::Unfulfilled,
                'tracking_token' => Str::random(24),
                'created_by' => $actor?->id,
            ]);
            $this->applyPlace($store, $order);
            $order->save();

            $this->replaceItems($order, $data['items'], $actor);
            $this->recalculate($order, $data['shipping_fee'] ?? null, true);
            $order->save();

            $this->log($order, 'created', ['source' => $order->source->value, 'total' => $order->total], $actor, $actorType);

            if (($data['confirmed'] ?? false) || ! $store->require_order_confirmation) {
                $this->transition($order, OrderStatus::Confirmed, $actor, ['note' => ($data['confirmed'] ?? false) ? 'Confirmée à la saisie' : 'Confirmation non requise']);
            }

            $this->customers->rememberAddress($customer, $order);
            $this->customers->refreshStats($customer);
            $order->forceFill(['risk_score' => $this->customers->riskScore($customer->fresh(), $order->id)])->save();

            return $order;
        });

        DB::afterCommit(fn () => $this->notifier->notify($store, Permission::OrdersView->value, new OrderPlaced($order), $actor));

        return $order->fresh(['items', 'customer']);
    }

    /**
     * Modification avant expédition : coordonnées, adresse, articles, remise, frais.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Order $order, array $data, ?User $actor): Order
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $order = $this->locked($order);
            if (! $order->status->isEditable()) {
                throw new BusinessRuleException('Une commande expédiée, livrée ou annulée ne se modifie plus.');
            }

            $before = $order->only(['customer_name', 'shipping_place_id', 'shipping_address', 'total', 'discount_total', 'shipping_fee']);
            $order->fill(Arr::only($data, [...self::CONTACT_FIELDS, 'discount_total', 'deposit_required']));
            $placeChanged = $order->isDirty('shipping_place_id') || $order->isDirty('delivery_method');
            if ($placeChanged) {
                $this->applyPlace($order->store, $order);
            }

            $itemsChanged = array_key_exists('items', $data);
            if ($itemsChanged) {
                $this->releaseItems($order, $actor, 'Commande modifiée');
                $order->items()->delete();
                $this->replaceItems($order, $data['items'], $actor);
            }

            $this->recalculate($order, $data['shipping_fee'] ?? null, $placeChanged || $itemsChanged || array_key_exists('shipping_fee', $data));
            $order->save();
            $this->refreshPaymentStatus($order);

            $after = $order->only(array_keys($before));
            $changes = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
            $this->log($order, 'edited', ['fields' => $changes, 'items' => $itemsChanged], $actor);

            if ($order->customer && $order->wasChanged(['shipping_place_id', 'shipping_address'])) {
                $this->customers->rememberAddress($order->customer, $order);
            }
            $this->refreshRisk($order);

            return $order->fresh(['items', 'customer']);
        });
    }

    /**
     * @param  array{note?: string, reason?: CancelReason, collect_payment?: bool, method?: PaymentMethod, restock?: bool}  $context
     */
    public function transition(Order $order, OrderStatus $to, ?User $actor, array $context = []): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $context) {
            $order = $this->locked($order);
            $from = $order->status;

            if (! $from->canTransitionTo($to)) {
                throw new BusinessRuleException("Impossible de passer de « {$from->label()} » à « {$to->label()} ».");
            }

            match ($to) {
                OrderStatus::Confirmed => $order->forceFill(['confirmed_at' => $order->confirmed_at ?? now(), 'confirmed_by' => $order->confirmed_by ?? $actor?->id, 'callback_at' => null]),
                OrderStatus::Shipped => $this->shipItems($order, $actor)->forceFill(['shipped_at' => now()]),
                OrderStatus::Completed => $this->shipItems($order, $actor)->forceFill(['completed_at' => now(), 'shipped_at' => $order->shipped_at ?? now()]),
                OrderStatus::Cancelled => $this->releaseItems($order, $actor, 'Commande annulée')->forceFill([
                    'cancelled_at' => now(),
                    'cancel_reason' => $context['reason'] ?? CancelReason::Other,
                    'cancel_note' => $context['note'] ?? null,
                    'callback_at' => null,
                ]),
                OrderStatus::Returned => $this->returnItems($order, $actor, $context['restock'] ?? true)->forceFill(['returned_at' => now()]),
                default => null,
            };

            $order->status = $to;
            $order->save();

            $this->log($order, 'status_changed', array_filter([
                'from' => $from->value,
                'to' => $to->value,
                'reason' => isset($context['reason']) ? $context['reason']->value : null,
                'note' => $context['note'] ?? null,
                'restock' => $to === OrderStatus::Returned ? ($context['restock'] ?? true) : null,
            ], fn ($v) => $v !== null), $actor);

            // Livrée et payée sur place : encaissement du reste à payer
            if ($to === OrderStatus::Completed && ($context['collect_payment'] ?? false) && $order->amountDue() > 0) {
                $method = $context['method'] ?? PaymentMethod::Cash;
                $this->recordPayment($order, [
                    'amount' => $order->amountDue(),
                    'method' => $method,
                    'kind' => $method === PaymentMethod::Cash ? PaymentKind::CodCash : PaymentKind::CodMobile,
                ], $actor);
            }

            if ($order->customer) {
                $this->customers->refreshStats($order->customer);
            }

            return $order->fresh(['items', 'customer']);
        });
    }

    public function cancel(Order $order, CancelReason $reason, ?string $note, ?User $actor): Order
    {
        return $this->transition($order, OrderStatus::Cancelled, $actor, ['reason' => $reason, 'note' => $note]);
    }

    /**
     * Paiement reçu (acompte, Wave, espèces…), saisi à la main.
     *
     * @param  array{amount: int, method: PaymentMethod|string, kind?: PaymentKind|string, reference?: ?string, note?: ?string, paid_at?: mixed}  $data
     */
    public function recordPayment(Order $order, array $data, ?User $actor): Payment
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $order = $this->locked($order);
            $amount = (int) $data['amount'];

            if ($order->status === OrderStatus::Cancelled) {
                throw new BusinessRuleException('Cette commande est annulée.');
            }
            if ($amount <= 0 || $amount > $order->amountDue()) {
                throw new BusinessRuleException('Le montant doit être compris entre 1 et le reste à payer ('.$order->amountDue().' F).', 'amount');
            }

            $method = $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from($data['method']);
            $kind = isset($data['kind']) ? ($data['kind'] instanceof PaymentKind ? $data['kind'] : PaymentKind::from($data['kind']))
                : ($order->status->holdsStock() ? PaymentKind::Deposit : PaymentKind::Manual);

            $payment = Payment::create([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'kind' => $kind,
                'method' => $method,
                'amount' => $amount,
                'currency' => $order->store->currency,
                'status' => 'succeeded',
                'provider_reference' => $data['reference'] ?? null,
                'idempotency_key' => (string) Str::uuid(),
                'note' => $data['note'] ?? null,
                'recorded_by' => $actor?->id,
                'paid_at' => $data['paid_at'] ?? now(),
            ]);

            $this->refreshPaymentStatus($order);
            $this->log($order, 'payment_recorded', ['amount' => $amount, 'method' => $method->value, 'kind' => $kind->value], $actor);

            return $payment;
        });
    }

    /**
     * Tentative de confirmation (appel, WhatsApp). Confirmée : la commande passe à « Confirmée ». Faux numéro
     * ou refus : elle est annulée. Injoignable ou à rappeler : elle reste dans la file avec une relance.
     */
    public function recordConfirmation(Order $order, ConfirmationOutcome $outcome, string $channel, ?string $note, mixed $callbackAt, ?User $actor): Order
    {
        return DB::transaction(function () use ($order, $outcome, $channel, $note, $callbackAt, $actor) {
            $order = $this->locked($order);
            if ($order->status !== OrderStatus::PendingConfirmation) {
                throw new BusinessRuleException('Cette commande n\'est plus à confirmer.');
            }

            $callback = match ($outcome) {
                ConfirmationOutcome::NoAnswer => $callbackAt ?? now()->addHours(2),
                ConfirmationOutcome::Callback => $callbackAt ?? throw new BusinessRuleException('Indiquez quand rappeler.', 'callback_at'),
                default => null,
            };

            OrderConfirmation::create([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'channel' => $channel,
                'outcome' => $outcome,
                'note' => $note,
                'callback_at' => $callback,
                'user_id' => $actor?->id,
            ]);
            $this->log($order, 'confirmation_attempt', array_filter(['outcome' => $outcome->value, 'channel' => $channel, 'note' => $note]), $actor);

            if ($outcome === ConfirmationOutcome::Confirmed) {
                return $this->transition($order, OrderStatus::Confirmed, $actor, ['note' => $note]);
            }
            if ($outcome->cancels()) {
                $reason = $outcome === ConfirmationOutcome::WrongNumber ? CancelReason::WrongNumber : CancelReason::CustomerRefused;

                return $this->cancel($order, $reason, $note, $actor);
            }

            $order->forceFill(['callback_at' => $callback])->save();

            return $order->fresh(['items', 'customer']);
        });
    }

    public function addNote(Order $order, string $note, ?User $actor): OrderEvent
    {
        return $this->log($order, 'note', ['note' => $note], $actor);
    }

    public function log(Order $order, string $type, array $data, ?User $actor, string $actorType = 'user'): OrderEvent
    {
        return OrderEvent::create([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'type' => $type,
            'data' => $data ?: null,
            'actor_type' => $actor ? 'user' : $actorType,
            'user_id' => $actor?->id,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceItems(Order $order, array $lines, ?User $actor): void
    {
        if ($lines === []) {
            throw new BusinessRuleException('Ajoutez au moins un article.', 'items');
        }

        $variants = ProductVariant::forStore($order->store_id)->with(['product', 'image'])
            ->whereIn('id', collect($lines)->pluck('variant_id')->filter())->get()->keyBy('id');
        $location = $order->location ?? $this->stock->defaultLocation($order->store);

        foreach (array_values($lines) as $index => $line) {
            $quantity = (int) $line['quantity'];
            if ($quantity <= 0) {
                throw new BusinessRuleException('Quantité invalide.', "items.{$index}.quantity");
            }

            $variant = isset($line['variant_id']) ? $variants->get($line['variant_id']) : null;
            if (isset($line['variant_id']) && ($variant === null || $variant->product === null)) {
                throw new BusinessRuleException('Article introuvable.', "items.{$index}.variant_id");
            }
            if ($variant === null && blank($line['title'] ?? null)) {
                throw new BusinessRuleException('Indiquez le nom de l\'article.', "items.{$index}.title");
            }

            $unitPrice = (int) ($line['unit_price'] ?? $variant?->price ?? 0);
            $item = OrderItem::create([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'variant_id' => $variant?->id,
                'product_id' => $variant?->product_id,
                'title' => $variant ? $variant->product->title : trim($line['title']),
                'variant_title' => $variant && ! $variant->is_default ? $variant->title : null,
                'sku' => $variant?->sku,
                'image_path' => $variant ? ($variant->image?->path ?? $variant->product->images()->value('path')) : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'compare_at_price' => $variant?->compare_at_price,
                'unit_cost' => $variant?->cost_price,
                'total' => $unitPrice * $quantity,
                'weight_g' => $variant?->weight_g,
                'requires_shipping' => $variant?->product->requires_shipping ?? true,
                'stock_location_id' => $variant ? $location->id : null,
            ]);

            if ($variant && $variant->product->track_inventory) {
                try {
                    $this->stock->reserve($actor, $variant, $location, $quantity, $order);
                } catch (BusinessRuleException $e) {
                    throw new BusinessRuleException($e->getMessage(), "items.{$index}.quantity");
                }
                $item->forceFill(['stock_state' => 'reserved'])->save();
            }
        }

        $order->unsetRelation('items');
    }

    private function releaseItems(Order $order, ?User $actor, string $note): Order
    {
        foreach ($order->items()->where('stock_state', 'reserved')->with(['variant.product', 'location'])->get() as $item) {
            $this->stock->release($actor, $item->variant, $item->location, $item->quantity, $order, $note);
            $item->forceFill(['stock_state' => 'released'])->save();
        }

        return $order;
    }

    private function shipItems(Order $order, ?User $actor): Order
    {
        foreach ($order->items()->with(['variant.product', 'location'])->get() as $item) {
            if ($item->stock_state === 'reserved') {
                $this->stock->ship($actor, $item->variant, $item->location, $item->quantity, $order);
                $item->stock_state = 'shipped';
            }
            $item->fulfilled_quantity = $item->quantity;
            $item->save();
        }

        $order->fulfillment_status = FulfillmentStatus::Fulfilled;

        return $order;
    }

    private function returnItems(Order $order, ?User $actor, bool $restock): Order
    {
        foreach ($order->items()->with(['variant.product', 'location'])->get() as $item) {
            if ($restock && $item->stock_state === 'shipped' && $item->variant && $item->location) {
                $this->stock->returnToStock($actor, $item->variant, $item->location, $item->quantity, $order);
                $item->stock_state = 'returned';
            }
            $item->returned_quantity = $item->quantity;
            $item->save();
        }

        $order->fulfillment_status = FulfillmentStatus::Returned;

        return $order;
    }

    /**
     * Sous-total, frais de livraison (zone du lieu, sauf montant saisi), total et coût.
     */
    private function recalculate(Order $order, ?int $shippingFee, bool $refreshFee): void
    {
        $items = $order->items()->get();
        $order->subtotal = (int) $items->sum('total');
        $order->cost_total = $items->every(fn ($i) => $i->unit_cost !== null || $i->variant_id === null)
            ? (int) $items->sum(fn ($i) => ($i->unit_cost ?? 0) * $i->quantity) : null;

        if ($shippingFee !== null) {
            $order->shipping_fee = $shippingFee;
        } elseif ($refreshFee) {
            $order->shipping_fee = $order->delivery_method === 'pickup' ? 0 : ($order->zone?->feeFor($order->subtotal) ?? 0);
        }

        $order->discount_total = min($order->discount_total, $order->subtotal);
        $order->total = $order->subtotal - $order->discount_total + $order->shipping_fee;
    }

    private function refreshPaymentStatus(Order $order): void
    {
        $paid = (int) $order->payments()->where('status', 'succeeded')->sum('amount');

        $order->forceFill([
            'amount_paid' => $paid,
            'payment_status' => match (true) {
                $paid <= 0 => PaymentStatus::Unpaid,
                $paid >= $order->total => PaymentStatus::Paid,
                default => PaymentStatus::PartiallyPaid,
            },
        ])->save();
    }

    private function refreshRisk(Order $order): void
    {
        if ($order->customer) {
            $order->forceFill(['risk_score' => $this->customers->riskScore($order->customer, $order->id)])->save();
        }
    }

    private function applyPlace(Store $store, Order $order): void
    {
        if ($order->delivery_method === 'pickup') {
            $order->forceFill(['delivery_zone_id' => null]);

            return;
        }

        $place = $order->shipping_place_id ? Place::visibleTo($store->id)->with('parent.parent')->find($order->shipping_place_id) : null;
        $order->forceFill([
            'shipping_place_id' => $place?->id,
            'shipping_place_label' => $place?->label(),
            'delivery_zone_id' => $this->places->zoneFor($store, $place)?->id,
        ]);
        $order->unsetRelation('zone');
    }

    private function location(Store $store, ?int $locationId): StockLocation
    {
        return $locationId
            ? StockLocation::forStore($store->id)->where('is_active', true)->findOrFail($locationId)
            : $this->stock->defaultLocation($store);
    }

    /**
     * Numéro de commande suivant de la boutique (sous verrou : pas de doublon entre deux ventes simultanées).
     */
    private function nextNumber(Store $store): int
    {
        $locked = Store::whereKey($store->id)->lockForUpdate()->first();
        $number = $locked->next_order_number;
        $locked->forceFill(['next_order_number' => $number + 1])->save();

        return $number;
    }

    private function locked(Order $order): Order
    {
        return Order::forStore($order->store_id)->with('store')->lockForUpdate()->findOrFail($order->id);
    }
}
