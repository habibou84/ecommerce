<?php

namespace Tests\Feature\Orders;

use App\Enums\CancelReason;
use App\Enums\ConfirmationOutcome;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Store;
use App\Notifications\LowStock;
use App\Notifications\OrderPlaced;
use App\Services\Catalog\CatalogWriter;
use App\Services\Customers\CustomerBook;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class OrderDeskTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['require_order_confirmation' => true]);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, [
            'title' => 'Robe wax',
            'options' => [['name' => 'Taille', 'values' => ['S', 'M']]],
            'variants' => [
                ['options' => ['S'], 'price' => 15000, 'cost_price' => 6000, 'on_hand' => 5],
                ['options' => ['M'], 'price' => 16000, 'cost_price' => 6000, 'on_hand' => 1],
            ],
        ]);
    }

    private function desk(): OrderDesk
    {
        return app(OrderDesk::class);
    }

    private function place(string $name): Place
    {
        return Place::whereNull('store_id')->where('name', $name)->firstOrFail();
    }

    private function create(array $overrides = []): Order
    {
        return $this->desk()->create($this->store, array_merge([
            'source' => 'whatsapp',
            'customer_name' => 'Fatou Diallo',
            'customer_phone' => '+2250506070809',
            'delivery_method' => 'delivery',
            'shipping_place_id' => $this->place('Angré')->id,
            'shipping_address' => 'Cité Arcades',
            'shipping_landmark' => 'Face pharmacie',
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 2]],
        ], $overrides), $this->store->owner);
    }

    private function available(int $variantIndex): array
    {
        $level = StockLevel::where('variant_id', $this->robe->variants[$variantIndex]->id)->first();

        return [$level->on_hand, $level->reserved];
    }

    public function test_an_order_is_numbered_priced_and_reserves_stock(): void
    {
        $order = $this->create();
        $second = $this->create(['customer_phone' => '+2250102030405']);

        $this->assertSame([1001, 1002], [$order->number, $second->number]);
        $this->assertSame('#1001', $order->reference());
        $this->assertSame(OrderStatus::PendingConfirmation, $order->status);
        $this->assertSame('Angré, Cocody', $order->shipping_place_label);
        $this->assertSame('Abidjan', $order->zone->name);
        $this->assertSame([30000, 1500, 31500, 12000], [$order->subtotal, $order->shipping_fee, $order->total, $order->cost_total]);
        $this->assertSame(['Robe wax', 'S', 'reserved'], [$order->items[0]->title, $order->items[0]->variant_title, $order->items[0]->stock_state]);
        $this->assertSame([5, 4], $this->available(0));

        $customer = $order->customer;
        $this->assertSame(['Fatou Diallo', 1], [$customer->name, $customer->orders_count]);
        $this->assertSame('Cité Arcades', $customer->addresses()->first()->address_line);
        $this->assertSame(['created'], $order->events()->pluck('type')->all());
    }

    public function test_stock_shortage_free_items_and_free_shipping(): void
    {
        try {
            $this->create(['items' => [['variant_id' => $this->robe->variants[1]->id, 'quantity' => 2]]]);
            $this->fail('Rupture acceptée');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Stock insuffisant', $e->getMessage());
        }

        DeliveryZone::forStore($this->store->id)->update(['free_above' => 15000]);
        $order = $this->create(['items' => [
            ['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1],
            ['title' => 'Emballage cadeau', 'quantity' => 1, 'unit_price' => 1000],
        ], 'discount_total' => 2000]);

        $this->assertSame([16000, 0, 14000], [$order->subtotal, $order->shipping_fee, $order->total]);
        $this->assertNull($order->items[1]->stock_state);
        $this->assertSame(6000, $order->cost_total);

        $pickup = $this->create(['delivery_method' => 'pickup', 'shipping_place_id' => null]);
        $this->assertSame(0, $pickup->shipping_fee);
    }

    public function test_confirmation_queue_outcomes(): void
    {
        $order = $this->create();

        $order = $this->desk()->recordConfirmation($order, ConfirmationOutcome::NoAnswer, 'call', null, null, $this->store->owner);
        $this->assertSame(OrderStatus::PendingConfirmation, $order->status);
        $this->assertTrue($order->callback_at->between(now()->addMinutes(119), now()->addMinutes(121)));

        $order = $this->desk()->recordConfirmation($order, ConfirmationOutcome::Confirmed, 'whatsapp', 'OK pour demain', null, $this->store->owner);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertNull($order->callback_at);
        $this->assertSame($this->store->owner_id, $order->confirmed_by);
        $this->assertSame(2, $order->confirmations()->count());

        $this->expectException(BusinessRuleException::class);
        $this->desk()->recordConfirmation($order, ConfirmationOutcome::Confirmed, 'call', null, null, null);
    }

    public function test_a_refusal_cancels_releases_stock_and_counts_against_the_customer(): void
    {
        $order = $this->create();

        $order = $this->desk()->recordConfirmation($order, ConfirmationOutcome::Refused, 'call', 'Plus intéressée', null, null);

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(CancelReason::CustomerRefused, $order->cancel_reason);
        $this->assertSame([5, 0], $this->available(0));
        $this->assertSame(1, $order->customer->refused_count);
        $this->assertSame(60 + 0, $this->create()->risk_score); // 1 refus sur 1 décidée
    }

    public function test_shipping_completing_with_payment_and_returning(): void
    {
        $order = $this->create(['confirmed' => true]);
        $this->assertSame(OrderStatus::Confirmed, $order->status);

        $this->desk()->recordPayment($order, ['amount' => 10000, 'method' => 'wave'], null);
        $order = $order->fresh();
        $this->assertSame([PaymentStatus::PartiallyPaid, 10000, 21500], [$order->payment_status, $order->amount_paid, $order->amountDue()]);

        $order = $this->desk()->transition($order, OrderStatus::Shipped, null);
        $this->assertSame([3, 0], $this->available(0));
        $this->assertSame('shipped', $order->items[0]->stock_state);

        $order = $this->desk()->transition($order, OrderStatus::Completed, null, ['collect_payment' => true, 'method' => PaymentMethod::Cash]);
        $this->assertSame([PaymentStatus::Paid, 0], [$order->payment_status, $order->amountDue()]);
        $this->assertSame(['deposit', 'cod_cash'], $order->payments()->pluck('kind')->map->value->all());
        $this->assertSame([1, 31500], [$order->customer->delivered_count, $order->customer->total_spent]);

        $order = $this->desk()->transition($order, OrderStatus::Returned, null, ['restock' => true]);
        $this->assertSame([5, 0], $this->available(0));

        $this->expectException(BusinessRuleException::class);
        $this->desk()->transition($order, OrderStatus::Confirmed, null);
    }

    public function test_editing_items_moves_the_reservation(): void
    {
        $order = $this->create();

        $order = $this->desk()->update($order, [
            'items' => [['variant_id' => $this->robe->variants[1]->id, 'quantity' => 1]],
            'shipping_place_id' => $this->place('Siporex')->id,
        ], null);

        $this->assertSame([5, 0], $this->available(0));
        $this->assertSame([1, 1], $this->available(1));
        $this->assertSame([16000, 17500, 'Siporex, Yopougon'], [$order->subtotal, $order->total, $order->shipping_place_label]);
        $this->assertSame('edited', $order->events()->first()->type);

        $this->desk()->transition($order, OrderStatus::Confirmed, null);
        $shipped = $this->desk()->transition($order, OrderStatus::Shipped, null);
        $this->expectException(BusinessRuleException::class);
        $this->desk()->update($shipped, ['customer_name' => 'X'], null);
    }

    public function test_duplicates_raise_the_risk_and_blacklist_is_maximal(): void
    {
        $one = ['items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]]];
        $this->create($one);
        $duplicate = $this->create($one);
        $this->assertSame(30, $duplicate->risk_score);

        app(CustomerBook::class)->blacklist($duplicate->customer, 'Refus répétés');
        $this->assertSame(100, $this->create($one)->risk_score);
    }

    public function test_new_orders_notify_the_team_except_the_author(): void
    {
        $agent = $this->addMember($this->store, null, Role::ConfirmationAgent);
        $cashier = $this->addMember($this->store, null, Role::Cashier);

        $order = $this->create();

        $notification = DatabaseNotification::where('notifiable_id', $agent->id)->first();
        $this->assertSame('Nouvelle commande #1001', $notification->data['title']);
        $this->assertSame($this->store->id, $notification->store_id);
        $this->assertSame('/gestion/commandes/'.$order->id, $notification->data['url']);
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $cashier->id)->count()); // orders.view
        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $this->store->owner_id)->where('type', OrderPlaced::class)->count());
    }

    public function test_low_stock_is_notified_once_when_crossing_the_threshold(): void
    {
        $manager = $this->addMember($this->store, null, Role::Manager);
        $this->create(['items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 2]]]); // 5 → 3 (seuil 3)
        $this->create(['customer_phone' => '+2250102030405', 'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]]]); // 3 → 2

        $alerts = DatabaseNotification::where('notifiable_id', $manager->id)->where('type', LowStock::class)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame('Robe wax — S : 3 disponibles', $alerts[0]->data['body']);
    }
}
