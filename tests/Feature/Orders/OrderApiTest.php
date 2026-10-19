<?php

namespace Tests\Feature\Orders;

use App\Enums\Role;
use App\Models\Order;
use App\Models\Place;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    private Store $store;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->variantId = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac pagne', 'variants' => [['price' => 9000, 'on_hand' => 10]]])->variants[0]->id;
        $this->actingAs($this->store->owner);
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->storeApi($this->store, $method, $path, $data);
    }

    private function newOrder(array $overrides = []): array
    {
        return $this->api('POST', 'orders', array_merge([
            'source' => 'whatsapp',
            'customer_name' => 'Fatou',
            'customer_phone' => '05 06 07 08 09',
            'delivery_method' => 'delivery',
            'shipping_place_id' => Place::where('name', 'Angré')->value('id'),
            'items' => [['variant_id' => $this->variantId, 'quantity' => 1]],
        ], $overrides))->assertCreated()->json('data');
    }

    public function test_orders_are_created_listed_searched_and_detailed(): void
    {
        $created = $this->newOrder();
        $this->assertSame('#1001', $created['reference']);
        $this->newOrder(['customer_phone' => '0102030405', 'customer_name' => 'Awa', 'confirmed' => true]);

        $list = $this->api('GET', 'orders')->assertOk()->json();
        $this->assertCount(2, $list['data']);
        $this->assertSame(['pending_confirmation' => 1, 'confirmed' => 1, 'to_ship' => 1], array_intersect_key($list['counts'], array_flip(['pending_confirmation', 'confirmed', 'to_ship'])));
        $this->assertSame(['#1002'], array_column($this->api('GET', 'orders?status=to_ship')->json('data'), 'reference'));
        $this->assertSame(['#1001'], array_column($this->api('GET', 'orders?search=0506070809')->json('data'), 'reference'));
        $this->assertSame(['#1001'], array_column($this->api('GET', 'orders?search=1001')->json('data'), 'reference'));
        $this->assertSame(['#1002'], array_column($this->api('GET', 'orders?search=awa')->json('data'), 'reference'));

        $detail = $this->api('GET', "orders/{$created['id']}")->assertOk()->json('data');
        $this->assertSame([9000, 1500, 10500], [$detail['subtotal'], $detail['shipping_fee'], $detail['total']]);
        $this->assertSame('Angré, Cocody', $detail['shipping_place_label']);
        $this->assertSame(['confirmed', 'cancelled'], array_column($detail['next_statuses'], 'value'));
        $this->assertSame('confirm_request', $detail['messages'][0]['key']);
        $this->assertSame('tel:+2250506070809', $detail['call_url']);
        $this->assertSame(1, $detail['customer']['orders_count']);
    }

    public function test_validation_errors(): void
    {
        $this->api('POST', 'orders', ['source' => 'online_store', 'customer_phone' => 'abc', 'items' => [['quantity' => 0]]])
            ->assertStatus(422)->assertJsonValidationErrors(['source', 'customer_phone', 'delivery_method', 'items.0.quantity', 'items.0.title']);
        $this->api('POST', 'orders', [
            'source' => 'phone', 'customer_phone' => '0506070809', 'delivery_method' => 'pickup',
            'items' => [['variant_id' => $this->variantId, 'quantity' => 50]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_confirmation_workflow_through_the_api(): void
    {
        $order = $this->newOrder();
        $agent = $this->addMember($this->store, null, Role::ConfirmationAgent);
        $this->actingAs($agent);

        $queue = $this->api('GET', 'orders/confirmation-queue')->assertOk()->json('data');
        $this->assertSame([$order['id']], array_column($queue, 'id'));
        $this->assertStringStartsWith('https://wa.me/2250506070809?text=', $queue[0]['whatsapp']);

        // Modification demandée par le client : l'agent corrige la quantité
        $this->api('PUT', "orders/{$order['id']}", ['items' => [['variant_id' => $this->variantId, 'quantity' => 2]]])
            ->assertOk()->assertJsonPath('data.subtotal', 18000);

        $this->api('POST', "orders/{$order['id']}/confirmations", ['outcome' => 'callback', 'channel' => 'call'])
            ->assertStatus(422)->assertJsonValidationErrors('callback_at');
        $this->api('POST', "orders/{$order['id']}/confirmations", ['outcome' => 'confirmed', 'channel' => 'whatsapp'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        // Confirmée : l'agent ne la modifie plus et ne peut pas l'expédier
        $this->api('PUT', "orders/{$order['id']}", ['customer_name' => 'X'])->assertForbidden();
        $this->api('POST', "orders/{$order['id']}/status", ['status' => 'shipped'])->assertForbidden();
    }

    public function test_status_payments_and_documents(): void
    {
        $order = $this->newOrder(['confirmed' => true]);

        $this->api('POST', "orders/{$order['id']}/status", ['status' => 'cancelled'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->api('POST', "orders/{$order['id']}/payments", ['amount' => 5000, 'method' => 'orange_money', 'reference' => 'OM123'])
            ->assertOk()->assertJsonPath('data.payment_status', 'partially_paid')->assertJsonPath('data.amount_due', 5500);
        $this->api('POST', "orders/{$order['id']}/payments", ['amount' => 99999, 'method' => 'cash'])->assertStatus(422);

        $this->api('POST', "orders/{$order['id']}/status", ['status' => 'shipped'])->assertOk()->assertJsonPath('data.messages.0.key', 'shipped');
        $this->api('POST', "orders/{$order['id']}/status", ['status' => 'completed', 'collect_payment' => true, 'method' => 'cash'])
            ->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.amount_due', 0);

        $pdf = $this->withHeaders(['Referer' => $this->storeUrl($this->store)])->get($this->storeUrl($this->store, "/api/v1/orders/{$order['id']}/label"));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_parse_endpoint_recognizes_known_customers(): void
    {
        $this->newOrder(['shipping_address' => 'Cité Arcades']);

        $result = $this->api('POST', 'orders/parse', ['text' => 'Un sac pagne pour moi stp, 05 06 07 08 09 à Angré'])->assertOk()->json('data');

        $this->assertSame('+2250506070809', $result['phone']);
        $this->assertSame('Fatou', $result['customer']['name']);
        $this->assertSame('Cité Arcades', $result['customer']['address']['address_line']);
        $this->assertSame([$this->variantId], array_column($result['items'], 'variant_id'));
    }

    public function test_customers_file(): void
    {
        $order = $this->newOrder();
        $customerId = $this->api('GET', "orders/{$order['id']}")->json('data.customer_id');

        $this->assertSame(['Fatou'], array_column($this->api('GET', 'customers?search=0506')->json('data'), 'name'));
        $this->api('PUT', "customers/{$customerId}", ['name' => 'Fatou Diallo', 'tags' => ['VIP'], 'note' => 'Préfère WhatsApp'])
            ->assertOk()->assertJsonPath('data.tags', ['VIP']);
        $this->api('POST', "customers/{$customerId}/blacklist", ['reason' => 'Trois refus'])->assertOk()->assertJsonPath('data.is_blacklisted', true);
        $this->assertSame(['Fatou Diallo'], array_column($this->api('GET', 'customers?filter=blacklisted')->json('data'), 'name'));
        $detail = $this->api('GET', "customers/{$customerId}")->json('data');
        $this->assertSame(['#1001'], array_column($detail['orders'], 'reference'));
        $this->assertSame('Angré, Cocody', $detail['addresses'][0]['place_label']);

        $this->api('POST', 'customers', ['phone' => '0707070707', 'name' => 'Nouveau'])->assertCreated()->assertJsonPath('data.phone', '+2250707070707');
    }

    public function test_delivery_zones_and_places(): void
    {
        $angre = Place::where('name', 'Angré')->value('id');
        $zone = $this->api('POST', 'delivery-zones', ['name' => 'Cocody nord', 'fee' => 1000, 'free_above' => 30000, 'place_ids' => [$angre]])
            ->assertCreated()->json('data');
        $this->assertSame(['Angré, Cocody'], array_column($zone['places'], 'label'));

        // Angré facturé 1 000 F au lieu des 1 500 F d'Abidjan
        $order = $this->newOrder();
        $this->assertSame(1000, $this->api('GET', "orders/{$order['id']}")->json('data.shipping_fee'));

        // Un lieu n'appartient qu'à une zone
        $other = $this->api('POST', 'delivery-zones', ['name' => 'Autre', 'fee' => 2000, 'place_ids' => [$angre]])->json('data');
        $zones = collect($this->api('GET', 'delivery-zones')->json('data'))->keyBy('id');
        $this->assertSame([], $zones[$zone['id']]['places']);
        $this->assertCount(1, $zones[$other['id']]['places']);

        $this->assertSame('Yopougon', $this->api('GET', 'places?search=yop')->json('data.0.name'));
        $added = $this->api('POST', 'places', ['name' => 'Cité Sir', 'parent_id' => Place::where('name', 'Cocody')->value('id')])->assertCreated()->json('data');
        $this->assertSame('Cité Sir, Cocody', $added['label']);
        $this->assertSame('Cité Sir', $this->api('GET', 'places?search=cite sir')->json('data.0.name'));
    }

    public function test_notifications_dashboard_and_tracking_page(): void
    {
        $manager = $this->addMember($this->store, null, Role::Manager);
        $order = $this->newOrder();

        $this->actingAs($manager);
        $notifications = $this->api('GET', 'notifications')->assertOk()->json();
        $this->assertSame(1, $notifications['unread']);
        $this->assertSame('Nouvelle commande #1001', $notifications['data'][0]['title']);
        $this->api('POST', 'notifications/read')->assertOk();
        $this->assertSame(0, $this->api('GET', 'notifications')->json('unread'));

        $dashboard = $this->api('GET', 'dashboard')->assertOk()->json('data');
        $this->assertSame([1, 1, 10500], [$dashboard['orders']['today_count'], $dashboard['orders']['to_confirm'], $dashboard['orders']['today_amount']]);
        $this->assertArrayHasKey('stock', $dashboard);
        // Comparaison avec hier, ventes des 7 jours (aujourd'hui en dernier), messages non lus
        $this->assertSame([0, 0], [$dashboard['orders']['yesterday_count'], $dashboard['orders']['yesterday_amount']]);
        $this->assertCount(7, $dashboard['week']);
        $this->assertSame(['date' => now()->toDateString(), 'amount' => 10500, 'count' => 1], $dashboard['week'][6]);
        $this->assertSame(0, $dashboard['unread_messages']);

        Order::find($order['id'])->forceFill(['created_at' => now()->subDay()->startOfDay()])->save();
        $dashboard = $this->api('GET', 'dashboard')->json('data');
        $this->assertSame([0, 1, 10500], [$dashboard['orders']['today_count'], $dashboard['orders']['yesterday_count'], $dashboard['orders']['yesterday_amount']]);
        $this->assertSame(10500, $dashboard['week'][5]['amount']);
        Order::find($order['id'])->forceFill(['created_at' => now()])->save();

        $token = Order::find($order['id'])->tracking_token;
        $this->get($this->storeUrl($this->store, "/suivi/{$token}"))->assertOk()->assertSee('Commande #1001')->assertSee('Sac pagne')->assertDontSee('0506070809');
        $this->get($this->storeUrl($this->createStore(), "/suivi/{$token}"))->assertNotFound();
    }

    public function test_permissions_and_isolation(): void
    {
        $order = $this->newOrder();

        $this->actingAs($this->addMember($this->store, null, Role::StockKeeper));
        $this->api('GET', 'orders')->assertOk();
        $this->api('POST', 'orders', [])->assertForbidden();
        $this->api('GET', 'customers')->assertForbidden();

        $other = $this->createStore();
        $this->actingAs($other->owner);
        $this->storeApi($other, 'GET', "orders/{$order['id']}")->assertNotFound();
        $this->assertSame([], $this->storeApi($other, 'GET', 'orders')->json('data'));
        $this->assertSame(0, $this->storeApi($other, 'GET', 'notifications')->json('unread'));
    }
}
