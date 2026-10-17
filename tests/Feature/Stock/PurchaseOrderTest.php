<?php

namespace Tests\Feature\Stock;

use App\Models\CashMovement;
use App\Models\Place;
use App\Models\StockLevel;
use App\Models\StockReceipt;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
    }

    public function test_order_from_a_supplier_receive_in_two_times_and_pay(): void
    {
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'cost_price' => 5000, 'on_hand' => 12]]]);
        $variant = $robe->variants[0];
        foreach (range(1, 5) as $i) {
            app(OrderDesk::class)->create($this->store, [
                'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+22505060708'.$i.'0', 'delivery_method' => 'delivery',
                'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
                'items' => [['variant_id' => $variant->id, 'quantity' => 2]],
            ], $this->store->owner);
        }

        // Conseil : de quoi tenir 30 jours au rythme des ventes (10 vendues, 2 disponibles)
        $suggestion = $this->storeApi($this->store, 'GET', 'stock/purchase-orders/suggestions')->assertOk()->json('data.0');
        $this->assertSame([$variant->id, 10, 2, 5000], [$suggestion['variant_id'], $suggestion['sold_30d'], $suggestion['available'], $suggestion['unit_cost']]);
        $this->assertSame(max(10, 2 * $this->store->low_stock_threshold) - 2, $suggestion['quantity']);

        $location = $this->storeApi($this->store, 'GET', 'stock/locations')->json('data.0.id');
        $order = $this->storeApi($this->store, 'POST', 'stock/purchase-orders', [
            'supplier_name' => 'Tissus Adjamé', 'supplier_phone' => '07 08 09 10 11', 'location_id' => $location, 'expected_on' => now()->addDays(3)->toDateString(),
            'items' => [['variant_id' => $variant->id, 'quantity' => 10]],
        ])->assertCreated()->assertJsonPath('data.reference', 'BC-1')->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_cost', 50000)->assertJsonPath('data.supplier.name', 'Tissus Adjamé')->json('data');

        $sent = $this->storeApi($this->store, 'POST', "stock/purchase-orders/{$order['id']}/send")->assertOk()->assertJsonPath('data.status', 'sent')->json('data');
        $this->assertStringStartsWith('https://wa.me/2250708091011?text=', $sent['whatsapp_url']);
        $this->assertStringContainsString('- 10 × Robe wax (5', $sent['text']);

        // Réception en deux fois ; le second lot coûte plus cher
        $this->storeApi($this->store, 'POST', "stock/purchase-orders/{$order['id']}/receive", ['items' => [['variant_id' => $variant->id, 'quantity' => 6]]])
            ->assertOk()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.received_units', 6);
        $this->assertSame(18, StockLevel::where('variant_id', $variant->id)->sum('on_hand'));
        $this->storeApi($this->store, 'POST', "stock/purchase-orders/{$order['id']}/cancel")->assertStatus(422);
        $this->storeApi($this->store, 'POST', "stock/purchase-orders/{$order['id']}/receive", ['items' => [['variant_id' => $variant->id, 'quantity' => 4, 'unit_cost' => 5500]]])
            ->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.total_cost', 55000);
        $this->assertSame(22, StockLevel::where('variant_id', $variant->id)->sum('on_hand'));
        $this->assertSame(2, StockReceipt::where('purchase_order_id', $order['id'])->count());
        $this->assertSame('BC-1', StockReceipt::first()->reference);

        // Paiement par Wave : dépense « Achat de marchandises »
        $this->storeApi($this->store, 'POST', "stock/purchase-orders/{$order['id']}/pay", ['amount' => 30000, 'method' => 'wave'])->assertOk()->assertJsonPath('data.paid_amount', 30000);
        $expense = CashMovement::sole();
        $this->assertSame(['expense', 'stock', 30000, 'wave', $order['id']], [$expense->type, $expense->category, $expense->amount, $expense->method, $expense->purchase_order_id]);

        $this->storeApi($this->store, 'GET', 'stock/suppliers')->assertOk()->assertJsonPath('data.0.phone', '+2250708091011')->assertJsonPath('data.0.orders_count', 1);
        $this->storeApi($this->store, 'GET', 'stock/purchase-orders')->assertOk()->assertJsonPath('data.0.received_units', 10);
    }
}
