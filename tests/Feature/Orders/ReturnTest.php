<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Tests\TestCase;

class ReturnTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private Product $sac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
        $writer = app(CatalogWriter::class);
        $this->robe = $writer->save($this->store, null, [
            'title' => 'Robe wax', 'options' => [['name' => 'Taille', 'values' => ['S', 'M']]],
            'variants' => [['options' => ['S'], 'price' => 15000, 'on_hand' => 5], ['options' => ['M'], 'price' => 16000, 'on_hand' => 5]],
        ]);
        $this->sac = $writer->save($this->store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 9000, 'on_hand' => 5]]]);
    }

    private function deliveredOrder(): Order
    {
        $desk = app(OrderDesk::class);
        $order = $desk->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'shipping_address' => 'Cité Arcades', 'confirmed' => true,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 2], ['variant_id' => $this->sac->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);
        $order = $desk->transition($desk->transition($order, OrderStatus::Shipped, $this->store->owner), OrderStatus::Completed, $this->store->owner);
        $desk->recordPayment($order, ['amount' => $order->total, 'method' => PaymentMethod::Cash, 'kind' => PaymentKind::CodCash], $this->store->owner);

        return $order->fresh('items');
    }

    private function level(int $variantId): array
    {
        $l = StockLevel::where('variant_id', $variantId)->sole();

        return [$l->on_hand, $l->reserved];
    }

    public function test_exchange_a_size_and_return_an_item(): void
    {
        $order = $this->deliveredOrder();
        [$robeLine, $sacLine] = [$order->items[0], $order->items[1]];
        [$s, $m] = [$this->robe->variants[0]->id, $this->robe->variants[1]->id];
        $this->assertSame([3, 0], $this->level($s));

        $this->storeApi($this->store, 'POST', "orders/{$order->id}/returns", ['items' => [['order_item_id' => $robeLine->id, 'quantity' => 3]]])->assertStatus(422);

        // 1 robe S échangée contre une M, le sac rendu et remboursé
        $return = $this->storeApi($this->store, 'POST', "orders/{$order->id}/returns", [
            'items' => [
                ['order_item_id' => $robeLine->id, 'quantity' => 1, 'replacement_variant_id' => $m],
                ['order_item_id' => $sacLine->id, 'quantity' => 1],
            ],
            'reason' => 'Taille trop petite',
        ])->assertCreated()
            ->assertJsonPath('data.reference', 'RET-1')->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.returned_value', 24000)->assertJsonPath('data.refund_due', 8000)->json('data');

        // Commande d'échange : la robe M, déjà confirmée, payée par la valeur rendue ; le livreur sait quoi reprendre
        $exchange = Order::with('items')->findOrFail($return['exchange_order']['id']);
        $this->assertSame(OrderStatus::Confirmed, $exchange->status);
        $this->assertSame([16000, 16000, 0, 0], [$exchange->subtotal, $exchange->discount_total, $exchange->shipping_fee, $exchange->total]);
        $this->assertSame($m, $exchange->items->sole()->variant_id);
        $this->assertStringContainsString('reprendre 1 × Robe wax — S, 1 × Sac en pagne', $exchange->internal_note);
        $this->assertSame([5, 1], $this->level($m));

        // Il ne reste qu'une robe S à pouvoir rendre
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/returns", ['items' => [['order_item_id' => $robeLine->id, 'quantity' => 2]]])->assertStatus(422);

        // Articles revenus : remis en stock et différence remboursée
        $this->storeApi($this->store, 'POST', "returns/{$return['id']}/receive")->assertOk()
            ->assertJsonPath('data.status', 'received')->assertJsonPath('data.refunded_amount', 8000);
        $this->assertSame([4, 0], $this->level($s));
        $this->assertSame([5, 0], $this->level($this->sac->variants[0]->id));
        $this->assertSame(8000, $order->fresh()->amount_refunded);
        $this->storeApi($this->store, 'POST', "returns/{$return['id']}/receive")->assertStatus(422);

        $this->storeApi($this->store, 'GET', 'returns?status=received')->assertOk()->assertJsonPath('data.0.reference', 'RET-1');
        $events = collect($this->storeApi($this->store, 'GET', "orders/{$order->id}")->json('data.events'))->pluck('type');
        $this->assertContains('return_created', $events);
        $this->assertContains('return_received', $events);
    }

    public function test_a_cancelled_exchange_cancels_its_order(): void
    {
        $order = $this->deliveredOrder();
        $m = $this->robe->variants[1]->id;
        $return = $this->storeApi($this->store, 'POST', "orders/{$order->id}/returns", [
            'items' => [['order_item_id' => $order->items[0]->id, 'quantity' => 2, 'replacement_variant_id' => $m]], 'exchange_shipping_fee' => 1000,
        ])->assertCreated()->json('data');
        // Remplacement plus cher : le client paie la différence et la livraison
        $this->assertSame(2 * 16000 - 2 * 15000 + 1000, $return['exchange_order']['amount_due']);
        $this->assertSame(0, $return['refund_due']);

        $this->storeApi($this->store, 'POST', "returns/{$return['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(OrderStatus::Cancelled, Order::findOrFail($return['exchange_order']['id'])->status);
        $this->assertSame([5, 0], $this->level($m));

        // Une commande pas encore livrée ne se retourne pas ainsi
        $pending = Order::findOrFail($return['exchange_order']['id']);
        $this->storeApi($this->store, 'POST', "orders/{$pending->id}/returns", ['items' => [['order_item_id' => $pending->items()->value('id'), 'quantity' => 1]]])->assertStatus(422);
    }
}
