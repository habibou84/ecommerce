<?php

namespace Tests\Feature\Finance;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\CarrierConnection;
use App\Models\CarrierPayout;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\CourierRemittance;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashOverviewTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe', 'variants' => [['price' => 10000, 'on_hand' => 50]]]);
        $this->actingAs($this->store->owner);
    }

    // Vente au comptoir : retrait en boutique, 10 000 F
    private function sale(): Order
    {
        return app(OrderDesk::class)->create($this->store, [
            'source' => 'in_store', 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'pickup', 'confirmed' => true,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);
    }

    private function pay(Order $order, int $amount, string $method, array $extra = []): Payment
    {
        return app(OrderDesk::class)->recordPayment($order, ['amount' => $amount, 'method' => $method] + $extra, $this->store->owner);
    }

    public function test_where_the_money_is_and_what_came_in(): void
    {
        $desk = app(OrderDesk::class);

        // Comptoir : 10 000 F en espèces ; 4 000 F par Wave sur une vente livrée qui reste due (crédit de 6 000 F)
        $this->pay($this->sale(), 10000, 'cash');
        $credit = $this->sale();
        $this->pay($credit, 4000, 'wave');
        $credit->forceFill(['status' => OrderStatus::Completed, 'completed_at' => now()])->save();
        // Hier : hors de « aujourd'hui », compté sur 7 jours
        $this->pay($this->sale(), 9999, 'cash', ['paid_at' => now()->subDay()]);

        // Paiement en ligne : 10 000 F, 200 F de frais
        Payment::create(['store_id' => $this->store->id, 'order_id' => $this->sale()->id, 'kind' => 'online', 'method' => 'wave', 'amount' => 10000, 'fee' => 200,
            'currency' => 'XOF', 'status' => 'succeeded', 'idempotency_key' => (string) Str::uuid(), 'paid_at' => now()]);

        // Livreur de la boutique : 7 000 F encore en main, 5 000 F remis, 3 000 F payés par mobile money
        $courier = Courier::create(['store_id' => $this->store->id, 'name' => 'Koffi', 'phone' => '+2250102030405', 'status' => 'active']);
        $order = $this->sale();
        $shipment = Shipment::create(['store_id' => $this->store->id, 'order_id' => $order->id, 'sequence' => 1, 'mode' => 'own_courier', 'status' => 'delivered',
            'courier_id' => $courier->id, 'idempotency_key' => (string) Str::uuid(), 'cod_amount' => 7000, 'requested_at' => now()]);
        CashCollection::create(['store_id' => $this->store->id, 'shipment_id' => $shipment->id, 'courier_id' => $courier->id, 'amount' => 7000, 'method' => 'cash', 'collected_at' => now()]);
        CourierRemittance::create(['store_id' => $this->store->id, 'courier_id' => $courier->id, 'expected_amount' => 5000, 'received_amount' => 5000, 'difference' => 0, 'method' => 'cash', 'received_at' => now()]);
        $this->pay($order, 3000, 'orange_money', ['kind' => 'cod_mobile']);

        // Transporteur : reversement de 20 000 F par Wave
        $carrier = CarrierConnection::create(['store_id' => $this->store->id, 'driver' => CarrierConnection::MANUAL, 'name' => 'Moto Express', 'status' => 'active', 'webhook_uuid' => (string) Str::uuid()]);
        CarrierPayout::create(['store_id' => $this->store->id, 'connection_id' => $carrier->id, 'remote_id' => 'MAN-1', 'amount' => 20000, 'method' => 'wave', 'paid_at' => now()]);

        // Sorties : remboursement de 1 000 F, paie du livreur 2 500 F en espèces ; restent à payer 800 F de gains et un relevé de 1 500 F
        Refund::create(['store_id' => $this->store->id, 'order_id' => $credit->id, 'amount' => 1000, 'method' => 'manual', 'status' => 'succeeded', 'refunded_at' => now()]);
        CourierPayout::create(['store_id' => $this->store->id, 'courier_id' => $courier->id, 'period_end' => now(), 'total' => 2500, 'status' => 'paid', 'method' => 'cash', 'paid_at' => now()]);
        CourierPayout::create(['store_id' => $this->store->id, 'courier_id' => $courier->id, 'period_end' => now(), 'total' => 1500, 'status' => 'draft']);
        CourierEarning::create(['store_id' => $this->store->id, 'courier_id' => $courier->id, 'type' => 'delivery', 'amount' => 800, 'created_at' => now()]);

        $today = $this->storeApi($this->store, 'GET', 'cash-overview')->assertOk();
        $today->assertJsonPath('data.period', 'today')
            ->assertJsonPath('data.position.couriers_cash', 7000)->assertJsonPath('data.position.couriers_cash_count', 1)
            ->assertJsonPath('data.position.carriers_due', 0)
            ->assertJsonPath('data.position.customers_due', 6000)->assertJsonPath('data.position.customers_due_count', 1)
            ->assertJsonPath('data.position.customers_due_orders.0.id', $credit->id)
            ->assertJsonPath('data.position.couriers_to_pay', 2300);

        $lines = collect($today->json('data.flows.lines'))->keyBy('key');
        $this->assertSame([14000, 10000, 2], [$lines['counter']['amount'], $lines['counter']['cash'], $lines['counter']['count']]);
        $this->assertSame([5000, 5000], [$lines['courier_remittances']['amount'], $lines['courier_remittances']['cash']]);
        $this->assertSame(3000, $lines['courier_mobile']['amount']);
        $this->assertSame([20000, 0], [$lines['carrier_payouts']['amount'], $lines['carrier_payouts']['cash']]);
        $this->assertSame(9800, $lines['online']['amount']);
        $this->assertSame(-1000, $lines['refunds']['amount']);
        $this->assertSame([-2500, -2500], [$lines['courier_pay']['amount'], $lines['courier_pay']['cash']]);
        $today->assertJsonPath('data.flows.total', 48300)->assertJsonPath('data.flows.cash', 12500)->assertJsonPath('data.flows.online_fees', 200);

        // Sur 7 jours : la vente d'hier en plus
        $this->storeApi($this->store, 'GET', 'cash-overview?period=week')->assertOk()
            ->assertJsonPath('data.flows.total', 48300 + 9999)->assertJsonPath('data.flows.cash', 12500 + 9999);
        $this->storeApi($this->store, 'GET', 'cash-overview?period=year')->assertStatus(422);

        // Caissière : oui ; magasinier (pas de « finances ») : non
        $this->actingAs($this->addMember($this->store, User::factory()->create(), Role::Cashier));
        $this->storeApi($this->store, 'GET', 'cash-overview')->assertOk();
        $this->actingAs($this->addMember($this->store, User::factory()->create(), Role::StockKeeper));
        $this->storeApi($this->store, 'GET', 'cash-overview')->assertForbidden();
    }
}
