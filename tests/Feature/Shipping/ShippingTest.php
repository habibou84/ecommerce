<?php

namespace Tests\Feature\Shipping;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Models\CarrierConnection;
use App\Models\CarrierPayout;
use App\Models\CarrierZone;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Reports\DailyReport;
use App\Services\Shipping\CarrierConnections;
use App\Services\Shipping\Drivers\RemoteShipment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Tests\TestCase;

class ShippingTest extends TestCase
{
    private const API = 'https://rapide.jibiat.com/api/public/v1';

    private const KEY = 'lv_abcd1234_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private Store $store;

    private Product $robe;

    /** @var array<string, \Closure> Réponses de l'instance Delivery App simulée, la plus récente d'abord */
    private array $remote = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            foreach ($this->remote as $pattern => $response) {
                [$method, $url] = str_contains($pattern, ' ') ? explode(' ', $pattern, 2) : [null, $pattern];
                if (($method === null || $method === $request->method()) && Str::is($url, $request->url())) {
                    return $response();
                }
            }

            return Http::response(['message' => 'Introuvable'], 404);
        });
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode', 'phone' => '+2250700000001']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, [
            'title' => 'Robe wax',
            'options' => [['name' => 'Taille', 'values' => ['S', 'M']]],
            'variants' => [['options' => ['S'], 'price' => 15000, 'on_hand' => 5], ['options' => ['M'], 'price' => 15000, 'on_hand' => 5]],
        ]);
        $this->actingAs($this->store->owner);
    }

    /**
     * Course telle que Jibiat l'envoie : l'exemple du contrat partagé (habibou84/socle), au statut voulu.
     */
    private function remoteOrder(string $status, array $extra = []): array
    {
        return array_replace_recursive(Contract::example('order-pending')['data'], ['status' => $status, 'updated_at' => now()->toIso8601String()], $extra);
    }

    /**
     * @param  array<string, mixed>  $routes  « [MÉTHODE ]url » => réponse
     */
    private function stub(array $routes): void
    {
        foreach (array_reverse($routes, true) as $pattern => $response) {
            unset($this->remote[$pattern]);
            $this->remote = [$pattern => fn () => $response instanceof \Closure ? $response() : $response] + $this->remote;
        }
    }

    private function fakeDeliveryApp(): void
    {
        $this->stub([
            self::API.'/me' => Http::response(Contract::example('me')),
            self::API.'/zones' => Http::response(Contract::example('zones')),
            self::API.'/webhooks' => Http::response(['secret' => 'whsec_secret'] + Contract::example('webhook-created'), 201),
            self::API.'/quotes' => Http::response(Contract::example('quote')),
            self::API.'/orders' => Http::response(['data' => $this->remoteOrder('pending')], 201),
        ]);
    }

    private function connect(): CarrierConnection
    {
        $this->fakeDeliveryApp();
        $id = $this->storeApi($this->store, 'POST', 'carriers/delivery-app', ['address' => 'rapide.jibiat.com', 'api_key' => self::KEY])
            ->assertCreated()->json('data.id');

        return CarrierConnection::findOrFail($id);
    }

    private function place(string $name): Place
    {
        return Place::whereNull('store_id')->where('name', $name)->firstOrFail();
    }

    private function confirmedOrder(string $placeName = 'Angré'): Order
    {
        return app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '+2250506070809',
            'delivery_method' => 'delivery', 'shipping_place_id' => $this->place($placeName)->id,
            'shipping_address' => 'Cité Arcades', 'shipping_landmark' => 'Face pharmacie', 'confirmed' => true,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 2]],
        ], $this->store->owner);
    }

    private function ship(Order $order, CarrierConnection $connection): Shipment
    {
        $id = $this->storeApi($this->store, 'POST', "orders/{$order->id}/shipments", ['carrier_connection_id' => $connection->id])
            ->assertCreated()->json('data.id');

        return Shipment::findOrFail($id);
    }

    private function webhook(CarrierConnection $connection, string $event, array $data, string $id = 'evt-1', ?string $secret = null): TestResponse
    {
        $body = json_encode(['id' => $id, 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data]);
        $timestamp = time();

        return $this->call('POST', 'http://api.localhost/webhooks/carriers/'.$connection->webhook_uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_ID' => $id,
            'HTTP_X_WEBHOOK_EVENT' => $event,
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign($secret ?? $connection->webhook_secret, $timestamp, $body),
        ], $body);
    }

    /**
     * Webhook réel du contrat partagé (corps exact de Jibiat, signé).
     */
    private function contractWebhook(CarrierConnection $connection, string $example): TestResponse
    {
        $payload = Contract::example($example);

        return $this->webhook($connection, $payload['event'], $payload['data'], $payload['id'].'-'.Str::random(4));
    }

    private function stock(int $variantIndex = 0): array
    {
        $level = StockLevel::where('variant_id', $this->robe->variants[$variantIndex]->id)->first();

        return [$level->on_hand, $level->reserved];
    }

    public function test_connecting_a_delivery_app_company_by_key(): void
    {
        $connection = $this->connect();

        $this->assertSame(['active', 'Rapide Express', '77', 'whsec_secret'], [$connection->status, $connection->name, $connection->remote_webhook_id, $connection->webhook_secret]);
        $this->assertSame(3, CarrierZone::where('connection_id', $connection->id)->count());
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/webhooks'
            && $r['url'] === 'http://api.localhost/webhooks/carriers/'.$connection->webhook_uuid
            && $r->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $r->hasHeader('X-Client-App', 'jibiat-marchand'));

        // Correspondance automatique : Angré → Angré, Siporex → sa commune Yopougon, Plateau non couvert
        $zones = collect($this->storeApi($this->store, 'GET', "carriers/{$connection->id}/zones")->assertOk()->json('data.places'))->keyBy('label');
        $byName = CarrierZone::where('connection_id', $connection->id)->pluck('id', 'name');
        $this->assertSame($byName['Angré'], $zones['Angré, Cocody']['carrier_zone_id']);
        $this->assertSame($byName['Yopougon'], $zones['Siporex, Yopougon']['carrier_zone_id']);
        $this->assertNull($zones['Plateau']['carrier_zone_id']);

        $this->storeApi($this->store, 'GET', 'carriers')->assertOk()
            ->assertJsonPath('data.0.webhook_auto', true)->assertJsonPath('data.0.merchant_name', 'Awa Mode')->assertJsonMissingPath('data.0.api_key');

        // Même entreprise, même compte : refusé
        $this->storeApi($this->store, 'POST', 'carriers/delivery-app', ['address' => 'rapide.jibiat.com', 'api_key' => self::KEY])->assertStatus(422);
    }

    public function test_connection_errors_are_explained(): void
    {
        $this->storeApi($this->store, 'POST', 'carriers/delivery-app', ['address' => 'evil.example.com', 'api_key' => self::KEY])
            ->assertStatus(422)->assertJsonValidationErrors('address');
        $this->storeApi($this->store, 'POST', 'carriers/delivery-app', ['address' => 'rapide.jibiat.com', 'api_key' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors('api_key');

        $this->stub([self::API.'/me' => Http::response(['message' => 'Clé API manquante, invalide, expirée ou révoquée.'], 401)]);
        $this->storeApi($this->store, 'POST', 'carriers/delivery-app', ['address' => 'rapide.jibiat.com', 'api_key' => self::KEY])
            ->assertStatus(422)->assertJsonPath('message', 'Clé API refusée par rapide.jibiat.com : vérifiez-la ou créez-en une nouvelle.');

        $this->stub([self::API.'/me' => Http::response(['data' => ['merchant' => ['id' => 1], 'company' => ['name' => 'X'], 'key' => ['scopes' => ['orders:read']]]])]);
        $this->storeApi($this->store, 'POST', 'carriers/delivery-app', ['address' => 'rapide.jibiat.com', 'api_key' => self::KEY])
            ->assertStatus(422)->assertJsonPath('message', 'Cette clé n\'a pas les droits nécessaires (orders:write) : créez-en une avec toutes les portées.');
        $this->assertSame(0, CarrierConnection::count());
    }

    public function test_shipping_an_order_creates_the_course_and_follows_it_to_delivery(): void
    {
        $connection = $this->connect();
        $order = $this->confirmedOrder();

        $this->storeApi($this->store, 'GET', "orders/{$order->id}/shipping-quotes")->assertOk()
            ->assertJsonPath('data.0.fee', 1200)->assertJsonPath('data.0.zone', 'Angré (Cocody)');

        $shipment = $this->ship($order, $connection);
        $this->assertSame(['LV-7K2M-QX9P', 'requested', 1200, '4821'], [$shipment->carrier_tracking_code, $shipment->status->value, $shipment->carrier_fee, $shipment->delivery_code]);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);

        $angre = CarrierZone::where('connection_id', $connection->id)->where('name', 'Angré')->value('remote_id');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/orders' && $r->method() === 'POST'
            && $r['merchant_reference'] === '#1001-1'
            && $r['delivery_zone_id'] === (int) $angre
            && $r['recipient_phone'] === '+2250506070809'
            && $r['items_amount'] === 31500 && $r['fee_payer'] === 'merchant'
            && $r['items'][0] === ['label' => 'Robe wax — S', 'quantity' => 2, 'unit_price' => 15000]
            && $r['pickup_contact_name'] === 'Awa Mode'
            && $r->hasHeader('Idempotency-Key', $shipment->idempotency_key));

        // Ramassage : le stock sort
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('picked_up')], 'evt-1')->assertOk();
        $this->assertSame([OrderStatus::Shipped, 'in_transit'], [$order->fresh()->status, $shipment->fresh()->status->value]);
        $this->assertSame([3, 0], $this->stock());

        // Même webhook reçu deux fois : ignoré ; signature fausse : refusée
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('picked_up')], 'evt-1')->assertOk();
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('delivered')], 'evt-x', 'whsec_faux')->assertStatus(401);
        $this->assertSame(1, $shipment->events()->where('status', 'in_transit')->count());

        // Livrée : commande terminée, encaissement du transporteur noté
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('delivered', [
            'amounts' => ['collected_amount' => 31500],
            'proof' => ['photos' => [['type' => 'photo_delivery', 'url' => 'https://rapide.jibiat.com/photo?signature=x']]],
        ])], 'evt-2')->assertOk();

        $order->refresh();
        $this->assertSame([OrderStatus::Completed, 'paid', 0], [$order->status, $order->payment_status->value, $order->amountDue()]);
        $this->assertSame('carrier_cod', $order->payments()->first()->kind->value);
        $this->assertSame('https://rapide.jibiat.com/photo?signature=x', $shipment->fresh()->proof_photo_url);

        $detail = $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertOk();
        $detail->assertJsonPath('data.shipments.0.status', 'delivered')->assertJsonPath('data.shipments.0.carrier.name', 'Rapide Express');
        $this->assertSame(['requested', 'in_transit', 'delivered'], array_column($detail->json('data.shipments.0.events'), 'status'));

        // Page de suivi du client : lien du transporteur
        $this->get($this->storeUrl($this->store, '/suivi/'.$order->tracking_token))->assertOk()->assertSee('Rapide Express')->assertSee('LV-7K2M-QX9P');
    }

    public function test_cancel_reject_failure_and_return(): void
    {
        $connection = $this->connect();
        $order = $this->confirmedOrder();
        $shipment = $this->ship($order, $connection);

        $this->stub([self::API.'/orders/LV-7K2M-QX9P/cancel' => Http::response(['data' => $this->remoteOrder('cancelled')])]);
        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/cancel", ['reason' => 'Client absent'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame([5, 2], $this->stock());

        // Nouvelle course, refusée par le transporteur : alerte, commande de nouveau à expédier
        $this->stub([self::API.'/orders' => Http::response(['data' => $this->remoteOrder('pending', ['tracking_code' => 'LV-2'])], 201)]);
        $second = $this->ship($order, $connection);
        $this->assertSame(2, $second->sequence);
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('rejected', ['tracking_code' => 'LV-2'])], 'evt-r')->assertOk();
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertContains('shipment_rejected', DatabaseNotification::all()->pluck('data.kind'));

        // Troisième : échec, puis retour → remise en stock
        $this->stub([self::API.'/orders' => Http::response(['data' => $this->remoteOrder('pending', ['tracking_code' => 'LV-3'])], 201)]);
        $third = $this->ship($order, $connection);
        $this->webhook($connection, 'order.incident', ['order' => $this->remoteOrder('delivery_failed', ['tracking_code' => 'LV-3', 'last_incident' => 'Client injoignable', 'attempts_count' => 1])], 'evt-f')->assertOk();
        $this->assertSame(['failed', 'Client injoignable', 1], [$third->fresh()->status->value, $third->fresh()->failure_reason, $third->fresh()->attempts_count]);
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertSame([3, 0], $this->stock());

        $this->storeApi($this->store, 'POST', "shipments/{$third->id}/cancel")->assertStatus(422);

        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('returned', ['tracking_code' => 'LV-3'])], 'evt-ret')->assertOk();
        $this->assertSame(OrderStatus::Returned, $order->fresh()->status);
        $this->assertSame([5, 0], $this->stock());
    }

    public function test_a_late_webhook_does_not_move_the_status_backwards(): void
    {
        $connection = $this->connect();
        $shipment = $this->ship($this->confirmedOrder(), $connection);

        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('out_for_delivery')], 'evt-2')->assertOk();
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('confirmed', ['updated_at' => now()->subHour()->toIso8601String()])], 'evt-1')->assertOk();

        $this->assertSame('out_for_delivery', $shipment->fresh()->status->value);
    }

    public function test_carrier_times_are_only_compared_with_carrier_times(): void
    {
        $connection = $this->connect();

        // Horloge du serveur de Delivery App en retard de 10 minutes sur la nôtre ; course acceptée d'office à la création
        $this->stub([self::API.'/orders' => Http::response(['data' => $this->remoteOrder('confirmed', ['updated_at' => now()->subMinutes(10)->subSeconds(30)->toIso8601String()])], 201)]);
        $shipment = $this->ship($this->confirmedOrder(), $connection);
        $this->assertSame(['accepted', 'confirmed'], [$shipment->status->value, $shipment->carrier_status]);

        // Le changement suivant, à son heure à lui, passe aussi
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('picked_up', ['updated_at' => now()->subMinutes(10)->toIso8601String()])], 'evt-2')->assertOk();
        $this->assertSame('in_transit', $shipment->fresh()->status->value);

        // Mais un webhook plus ancien que le dernier reçu reste ignoré
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('confirmed', ['updated_at' => now()->subMinutes(11)->toIso8601String()])], 'evt-3')->assertOk();
        $this->assertSame('in_transit', $shipment->fresh()->status->value);
    }

    public function test_periodic_sync_catches_up_lost_webhooks(): void
    {
        $connection = $this->connect();
        $order = $this->confirmedOrder();
        $this->ship($order, $connection);

        $this->stub([self::API.'/orders*' => Http::response(['data' => [
            $this->remoteOrder('delivered', ['amounts' => ['collected_amount' => 31500]]),
            $this->remoteOrder('delivered', ['tracking_code' => 'LV-INCONNU']),
        ], 'meta' => ['last_page' => 1]])]);

        $this->artisan('shipping:sync')->assertSuccessful();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), self::API.'/orders?updated_since='));
        $this->assertNotNull($connection->fresh()->last_synced_at);
    }

    public function test_payouts_are_reconciled_with_shipments(): void
    {
        $connection = $this->connect();
        $order = $this->confirmedOrder();
        $shipment = $this->ship($order, $connection);
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('delivered', ['amounts' => ['collected_amount' => 31500]])], 'evt-d')->assertOk();

        $this->stub([self::API.'/payouts/RV-261011-00001' => Http::response(['data' => [
            'reference' => 'RV-261011-00001', 'net_amount' => 30300, 'total_collected' => 31500, 'total_fees' => 1200, 'method' => 'wave', 'paid_at' => now()->toIso8601String(),
            'entries' => [
                ['type' => 'cod_credit', 'amount' => 31500, 'order' => ['tracking_code' => 'LV-7K2M-QX9P']],
                ['type' => 'delivery_fee', 'amount' => -1200, 'order' => ['tracking_code' => 'LV-7K2M-QX9P']],
            ],
        ]])]);
        $this->webhook($connection, 'payout.paid', ['payout' => ['reference' => 'RV-261011-00001']], 'evt-p')->assertOk();

        $payout = CarrierPayout::with('lines')->sole();
        $this->assertSame(['matched', 0, 30300], [$payout->reconciliation_status, $payout->difference, $payout->amount]);
        $this->assertSame([$shipment->id, 30300, 30300], [$payout->lines[0]->shipment_id, $payout->lines[0]->net, $payout->lines[0]->expected_net]);

        // Frais retenus plus élevés que le tarif annoncé : écart signalé
        $this->stub([self::API.'/payouts/RV-2' => Http::response(['data' => [
            'reference' => 'RV-2', 'net_amount' => 29500, 'total_collected' => 31500, 'total_fees' => 2000,
            'entries' => [['type' => 'cod_credit', 'amount' => 31500, 'order' => ['tracking_code' => 'LV-7K2M-QX9P']], ['type' => 'delivery_fee', 'amount' => -2000, 'order' => ['tracking_code' => 'LV-7K2M-QX9P']]],
        ]])]);
        $this->webhook($connection, 'payout.paid', ['payout' => ['reference' => 'RV-2']], 'evt-p2')->assertOk();
        $this->storeApi($this->store, 'GET', 'carrier-payouts')->assertOk()
            ->assertJsonPath('data.0.reference', 'RV-2')->assertJsonPath('data.0.status', 'discrepancy')->assertJsonPath('data.0.difference', -800)
            ->assertJsonPath('data.0.lines.0.order_reference', '#1001');
    }

    public function test_balance_owed_by_each_carrier(): void
    {
        $connection = $this->connect();
        $order = $this->confirmedOrder();
        $shipment = $this->ship($order, $connection);

        // Rien de livré : rien à recevoir
        $this->storeApi($this->store, 'GET', 'carrier-balances')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('data.0.carrier', 'Rapide Express')->assertJsonPath('data.0.count', 0);

        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('delivered', ['amounts' => ['collected_amount' => 31500]])], 'evt-d')->assertOk();
        $this->travel(8)->days();

        $this->storeApi($this->store, 'GET', 'carrier-balances')->assertOk()
            ->assertJsonPath('total', 30300)->assertJsonPath('overdue_days', 7)
            ->assertJsonPath('data.0.amount', 30300)->assertJsonPath('data.0.count', 1)
            ->assertJsonPath('data.0.overdue_count', 1)->assertJsonPath('data.0.is_api', true);
        $this->storeApi($this->store, 'GET', "carrier-balances/{$connection->id}")->assertOk()
            ->assertJsonPath('data.0.order_reference', '#1001-1')->assertJsonPath('data.0.collected', 31500)
            ->assertJsonPath('data.0.fees', 1200)->assertJsonPath('data.0.net', 30300)->assertJsonPath('data.0.days', 8);

        $csv = $this->withCredentials()->get($this->storeUrl($this->store, "/api/v1/carrier-balances/{$connection->id}/export"))->assertOk();
        $this->assertStringContainsString('#1001-1;LV-7K2M-QX9P;', $csv->getContent());
        $this->assertStringContainsString('Total;;;;31500;1200;30300', $csv->getContent());

        $report = app(DailyReport::class);
        $this->assertStringContainsString('À recevoir des entreprises de livraison : 30 300 F (dont 30 300 F en retard)', $report->text($this->store, $report->figures($this->store, CarbonImmutable::now())));

        // Les reversements d'un transporteur avec API ne se saisissent pas à la main
        $this->storeApi($this->store, 'POST', "carriers/{$connection->id}/payouts", ['shipment_ids' => [$shipment->id], 'amount' => 30300])->assertStatus(422);

        // Reversement reçu : la course n'est plus à recevoir
        $this->stub([self::API.'/payouts/RV-1' => Http::response(['data' => [
            'reference' => 'RV-1', 'net_amount' => 30300, 'total_collected' => 31500, 'total_fees' => 1200,
            'entries' => [['type' => 'cod_credit', 'amount' => 31500, 'order' => ['tracking_code' => 'LV-7K2M-QX9P']], ['type' => 'delivery_fee', 'amount' => -1200, 'order' => ['tracking_code' => 'LV-7K2M-QX9P']]],
        ]])]);
        $this->webhook($connection, 'payout.paid', ['payout' => ['reference' => 'RV-1']], 'evt-p')->assertOk();
        $this->storeApi($this->store, 'GET', 'carrier-balances')->assertJsonPath('total', 0)->assertJsonPath('data.0.count', 0);

        // Commande déjà payée : la boutique doit la livraison au transporteur
        $shipment->payoutLines()->delete();
        $shipment->forceFill(['collected_amount' => 0])->save();
        $this->storeApi($this->store, 'GET', 'carrier-balances')->assertJsonPath('total', -1200);
    }

    public function test_paid_payouts_missed_by_webhook_are_caught_up_by_sync(): void
    {
        $connection = $this->connect();
        $this->ship($this->confirmedOrder(), $connection);
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('delivered', ['amounts' => ['collected_amount' => 31500]])], 'evt-d')->assertOk();

        $this->stub([
            self::API.'/payouts/RV-9' => Http::response(['data' => [
                'reference' => 'RV-9', 'net_amount' => 30300, 'total_collected' => 31500, 'total_fees' => 1200, 'paid_at' => now()->toIso8601String(),
                'entries' => [['type' => 'cod_credit', 'amount' => 31500, 'order' => ['tracking_code' => 'LV-7K2M-QX9P']], ['type' => 'delivery_fee', 'amount' => -1200, 'order' => ['tracking_code' => 'LV-7K2M-QX9P']]],
            ]]),
            self::API.'/payouts?*' => Http::response(['data' => [['reference' => 'RV-9', 'status' => 'paid']]]),
            self::API.'/orders?*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]]),
        ]);

        $this->artisan('shipping:sync')->assertSuccessful();
        $this->artisan('shipping:sync')->assertSuccessful();

        $this->assertSame(['RV-9', 'matched'], [CarrierPayout::sole()->remote_id, CarrierPayout::sole()->reconciliation_status]);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/payouts?status=paid&per_page=100&page=1');
        // Une fois un reversement reçu : seulement ceux payés depuis (marge de deux jours)
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), self::API.'/payouts?status=paid&paid_since='));
        $this->storeApi($this->store, 'GET', 'carrier-balances')->assertJsonPath('total', 0);
    }

    public function test_every_status_and_order_of_the_contract_is_understood(): void
    {
        foreach (Contract::ORDER_STATUSES as $status) {
            $this->assertNotNull(ShipmentStatus::fromDeliveryApp($status), "Statut Jibiat « {$status} » sans équivalent");
        }

        $read = fn (string $example) => RemoteShipment::fromDeliveryApp(Contract::example($example)['data']);
        $tomorrow = now()->addDay()->toDateString();
        $in2 = now()->addDays(2)->toDateString();

        $pending = $read('order-pending');
        $this->assertSame(['LV-7K2M-QX9P', ShipmentStatus::Requested, 1200, '4821', null, false, false], [$pending->trackingCode, $pending->status, $pending->fee, $pending->deliveryCode, $pending->scheduledDate, $pending->needsDecision, $pending->returnRequested]);
        $this->assertSame([ShipmentStatus::Accepted, $tomorrow, null], [$read('order-scheduled-tomorrow')->status, $read('order-scheduled-tomorrow')->scheduledDate, $read('order-scheduled-tomorrow')->rescheduledTo]);
        $this->assertSame(ShipmentStatus::InTransit, $read('order-picked-up')->status);
        $failed = $read('order-failed-needs-decision');
        $this->assertSame([ShipmentStatus::Failed, true, 'Client absent', 1], [$failed->status, $failed->needsDecision, $failed->incident, $failed->attempts]);
        $this->assertSame([ShipmentStatus::Rescheduled, $in2, $in2], [$read('order-rescheduled')->status, $read('order-rescheduled')->rescheduledTo, $read('order-rescheduled')->scheduledDate]);
        $this->assertSame([true, false], [$read('order-return-requested')->returnRequested, $read('order-return-requested')->needsDecision]);
        $delivered = $read('order-delivered');
        $this->assertSame([ShipmentStatus::Delivered, 31500], [$delivered->status, $delivered->collected]);
        $this->assertStringStartsWith('https://', (string) $delivered->proofPhotoUrl);
    }

    public function test_every_webhook_of_the_contract_is_understood(): void
    {
        $connection = $this->connect();
        $shipment = $this->ship($this->confirmedOrder(), $connection);

        $this->contractWebhook($connection, 'webhook-order-status-changed')->assertOk();
        $this->assertSame('in_transit', $shipment->fresh()->status->value);

        $this->contractWebhook($connection, 'webhook-order-incident')->assertOk();
        $this->assertSame(['failed', true], [$shipment->fresh()->status->value, $shipment->fresh()->needs_decision]);

        $this->contractWebhook($connection, 'webhook-order-updated')->assertOk();
        $this->assertSame(['rescheduled', now()->addDays(2)->toDateString()], [$shipment->fresh()->status->value, $shipment->fresh()->scheduled_date->toDateString()]);

        $this->stub([self::API.'/payouts/RV-261020-00001' => Http::response(Contract::example('payout'))]);
        $this->contractWebhook($connection, 'webhook-payout-paid')->assertOk();
        $this->assertSame(['RV-261020-00001', 30300], [CarrierPayout::sole()->remote_id, CarrierPayout::sole()->amount]);
    }

    public function test_dates_decisions_and_returns_follow_the_carrier_without_status_change(): void
    {
        $connection = $this->connect();
        $shipment = $this->ship($this->confirmedOrder(), $connection);
        // La boutique prévient elle-même son équipe : l'entreprise de livraison n'envoie pas ses alertes en double
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/orders' && $r->method() === 'POST' && $r['notify_merchant'] === false);

        $in2 = now()->addDays(2)->toDateString();
        $in3 = now()->addDays(3)->toDateString();
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('rescheduled', ['delivery' => ['scheduled_date' => $in2], 'last_incident' => 'Client absent'])], 'evt-1')->assertOk();
        $shipment->refresh();
        $this->assertSame(['rescheduled', $in2, $in2], [$shipment->status->value, $shipment->rescheduled_to->toDateString(), $shipment->scheduled_date->toDateString()]);
        $this->assertStringContainsString('nouvelle date le '.now()->addDays(2)->format('d/m/Y'), DatabaseNotification::all()->firstWhere('data.kind', 'shipment_rescheduled')->data['body']);

        // Reportée de nouveau, sans changement de statut : date suivie, équipe prévenue, ligne dans l'historique
        $this->webhook($connection, 'order.updated', ['order' => $this->remoteOrder('rescheduled', ['delivery' => ['scheduled_date' => $in3]]), 'change' => ['fields' => ['delivery_scheduled_date']]], 'evt-2')->assertOk();
        $shipment->refresh();
        $this->assertSame([$in3, $in3], [$shipment->scheduled_date->toDateString(), $shipment->rescheduled_to->toDateString()]);
        $this->assertSame(1, DatabaseNotification::all()->filter(fn ($n) => str_starts_with($n->data['title'], 'Date de livraison changée'))->count());
        $this->assertStringContainsString('Livraison prévue le', $shipment->events()->reorder('id', 'desc')->first()->note);

        // Décision attendue, puis retour demandé : affichés sur la fiche
        $this->webhook($connection, 'order.updated', ['order' => $this->remoteOrder('rescheduled', ['delivery' => ['scheduled_date' => $in3], 'needs_decision' => true])], 'evt-3')->assertOk();
        $this->assertTrue($shipment->fresh()->needs_decision);
        $this->webhook($connection, 'order.updated', ['order' => $this->remoteOrder('rescheduled', ['delivery' => ['scheduled_date' => $in3], 'needs_decision' => false, 'return_requested' => true])], 'evt-4')->assertOk();
        $this->storeApi($this->store, 'GET', "orders/{$shipment->order_id}")->assertOk()
            ->assertJsonPath('data.shipments.0.needs_decision', false)
            ->assertJsonPath('data.shipments.0.return_requested', true)
            ->assertJsonPath('data.shipments.0.scheduled_date', $in3);
        $this->assertSame(1, DatabaseNotification::all()->filter(fn ($n) => str_starts_with($n->data['title'], 'Date de livraison changée'))->count());
    }

    public function test_a_webhook_created_before_order_updated_is_recreated_once(): void
    {
        $connection = $this->connect();
        $this->assertSame(CarrierConnections::WEBHOOK_EVENTS, $connection->setting('webhook_events'));
        $connection->forceFill(['settings' => ['fee_payer' => 'store']])->save(); // connexion d'avant order.updated

        $this->stub([self::API.'/orders?*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]])]);
        $this->artisan('shipping:sync')->assertSuccessful();
        $this->artisan('shipping:sync')->assertSuccessful();

        $created = Http::recorded(fn (HttpRequest $r) => $r->url() === self::API.'/webhooks' && $r->method() === 'POST');
        $this->assertCount(2, $created); // connexion, puis une seule recréation
        $this->assertContains('order.updated', $created->last()[0]['events']);
        $this->assertSame(['store', CarrierConnections::WEBHOOK_EVENTS], [$connection->fresh()->setting('fee_payer'), $connection->fresh()->setting('webhook_events')]);
    }

    public function test_quotes_say_when_the_carrier_can_deliver(): void
    {
        $this->connect();
        $order = $this->confirmedOrder();
        $tomorrow = now()->addDay()->toDateString();
        $this->stub([self::API.'/delivery-day' => Http::response(['data' => ['closed' => true, 'message' => 'Heure limite passée : livraison demain.', 'earliest_delivery_date' => $tomorrow]])]);

        $this->storeApi($this->store, 'GET', "orders/{$order->id}/shipping-quotes")->assertOk()
            ->assertJsonPath('data.0.earliest_date', $tomorrow)
            ->assertJsonPath('data.0.day_message', 'Heure limite passée : livraison demain.');
    }

    public function test_an_expired_proof_photo_link_is_asked_again(): void
    {
        $connection = $this->connect();
        $shipment = $this->ship($this->confirmedOrder(), $connection);
        $old = 'https://rapide.jibiat.com/photo?expires='.now()->subHour()->timestamp.'&signature=x';
        $fresh = 'https://rapide.jibiat.com/photo?expires='.now()->addDay()->timestamp.'&signature=y';
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('delivered', ['amounts' => ['collected_amount' => 31500], 'proof' => ['photos' => [['type' => 'photo_delivery', 'url' => $old]]]])], 'evt-1')->assertOk();

        $this->storeApi($this->store, 'GET', "orders/{$shipment->order_id}")->assertJsonPath('data.shipments.0.proof_photo_url', '/api/v1/shipments/'.$shipment->id.'/proof');
        $this->stub([self::API.'/orders/LV-7K2M-QX9P' => Http::response(['data' => $this->remoteOrder('delivered', ['amounts' => ['collected_amount' => 31500], 'proof' => ['photos' => [['type' => 'photo_delivery', 'url' => $fresh]]]])])]);

        $this->storeApi($this->store, 'GET', "shipments/{$shipment->id}/proof")->assertRedirect($fresh);
        $this->assertSame($fresh, $shipment->fresh()->proof_photo_url);
    }

    public function test_carrier_zones_are_read_again_every_night(): void
    {
        $connection = $this->connect();
        $this->stub([self::API.'/zones' => Http::response(['data' => [
            ['id' => 1, 'parent_id' => null, 'name' => 'Cocody', 'is_shipping' => false, 'is_active' => true],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Angré', 'is_shipping' => false, 'is_active' => true],
            ['id' => 4, 'parent_id' => null, 'name' => 'Plateau', 'is_shipping' => false, 'is_active' => true],
        ]])]);

        $this->artisan('shipping:zones')->assertSuccessful();

        $zones = CarrierZone::where('connection_id', $connection->id)->pluck('is_active', 'name');
        $this->assertSame([true, false], [$zones['Plateau'], $zones['Yopougon']]);
    }

    public function test_the_store_acts_on_the_course_at_the_carrier(): void
    {
        $connection = $this->connect();
        $order = $this->confirmedOrder();
        $shipment = $this->ship($order, $connection);
        $code = self::API.'/orders/LV-7K2M-QX9P';
        $this->stub([
            "PATCH {$code}" => fn () => Http::response(['data' => $this->remoteOrder('pending', ['delivery' => ['scheduled_date' => now()->addDay()->toDateString()]])]),
            "POST {$code}/notes" => Http::response(['data' => ['tracking_code' => 'LV-7K2M-QX9P']], 201),
        ]);
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.shipments.0.carrier_actions', ['edit' => 'all', 'decide' => false, 'return' => false, 'note' => true]);

        // Avant le ramassage : tout se modifie, le montant à encaisser suit
        $this->storeApi($this->store, 'PATCH', "shipments/{$shipment->id}/carrier", ['delivery_landmark' => 'Portail bleu', 'items_amount' => 30000])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && $r->url() === $code && ($r->data()['delivery_landmark'] ?? null) === 'Portail bleu' && ($r->data()['items_amount'] ?? null) === 30000);
        $this->assertSame(30000, $shipment->fresh()->cod_amount);
        $this->assertStringContainsString('Course modifiée chez Rapide Express : repère, montant à encaisser', $shipment->events()->reorder('id', 'desc')->first()->note);

        // Commande modifiée dans la boutique : la course reprend l'adresse et le montant de la commande
        $order->forceFill(['shipping_address' => 'Cité Arcades, villa 12'])->save();
        $this->storeApi($this->store, 'PATCH', "shipments/{$shipment->id}/carrier", ['from_order' => true])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && ($r->data()['delivery_address'] ?? null) === 'Cité Arcades, villa 12' && ($r->data()['items_amount'] ?? null) === 31500 && ! isset($r->data()['pickup_address']));

        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/notes", ['note' => 'Appeler avant'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === "{$code}/notes" && $r['note'] === 'Appeler avant');
        // Avant le ramassage : pas de retour (on annule)
        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/return-request")->assertStatus(422);

        // Échec de livraison : la boutique décide sans quitter Jibiat Marchand
        $this->webhook($connection, 'order.status_changed', ['order' => $this->remoteOrder('delivery_failed', ['needs_decision' => true, 'last_incident' => 'Client absent'])], 'evt-f')->assertOk();
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.shipments.0.carrier_actions.decide', true)
            ->assertJsonPath('data.shipments.0.carrier_actions.edit', 'delivery');
        $tomorrow = now()->addDay()->toDateString();
        $this->stub(["POST {$code}/decision" => Http::response(['data' => $this->remoteOrder('rescheduled', ['delivery' => ['scheduled_date' => $tomorrow], 'needs_decision' => false])])]);
        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/decision", ['decision' => 'redeliver'])->assertJsonValidationErrors('date');
        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/decision", ['decision' => 'redeliver', 'date' => $tomorrow, 'note' => 'Après 14 h'])->assertOk()
            ->assertJsonPath('data.status', 'rescheduled')->assertJsonPath('data.needs_decision', false);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === "{$code}/decision" && $r['decision'] === 'redeliver' && $r['date'] === $tomorrow);
        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/decision", ['decision' => 'return'])->assertStatus(422);

        // Colis récupéré : retour demandé ; refus de l'entreprise repris tel quel
        $this->stub(["POST {$code}/return-request" => Http::response(['message' => 'Retour impossible à ce stade.'], 422)]);
        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/return-request", ['note' => 'Client a annulé'])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Retour impossible à ce stade.']);
    }

    public function test_carrier_actions_need_a_connected_api_carrier(): void
    {
        $manual = app(CarrierConnections::class)->connectManual($this->store, 'Moto Express', '0700000099');
        $shipment = $this->ship($this->confirmedOrder(), $manual);

        $this->storeApi($this->store, 'GET', "orders/{$shipment->order_id}")->assertJsonPath('data.shipments.0.carrier_actions', null);
        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/notes", ['note' => 'x'])->assertStatus(422);
    }

    public function test_payout_from_a_manual_carrier_is_entered_by_the_store(): void
    {
        $id = $this->storeApi($this->store, 'POST', 'carriers/manual', ['name' => 'Moto Express'])->assertCreated()->json('data.id');
        $first = $this->ship($this->confirmedOrder(), CarrierConnection::find($id));
        $second = $this->ship($this->confirmedOrder(), CarrierConnection::find($id));
        foreach ([$first, $second] as $shipment) {
            $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/status", ['status' => 'delivered', 'collected_amount' => 31500])->assertOk();
        }

        $this->storeApi($this->store, 'GET', 'carrier-balances')->assertJsonPath('data.0.carrier', 'Moto Express')
            ->assertJsonPath('data.0.is_api', false)->assertJsonPath('data.0.amount', 63000)->assertJsonPath('data.0.count', 2);

        // Le transporteur a gardé 1 500 F sur la première course
        $this->storeApi($this->store, 'POST', "carriers/{$id}/payouts", ['shipment_ids' => [$first->id], 'amount' => 30000, 'method' => 'wave', 'transaction_ref' => 'W-123'])
            ->assertCreated()->assertJsonPath('data.status', 'discrepancy')->assertJsonPath('data.difference', -1500);
        $this->storeApi($this->store, 'GET', 'carrier-balances')->assertJsonPath('data.0.amount', 31500)->assertJsonPath('data.0.count', 1);
        $this->storeApi($this->store, 'GET', 'carrier-payouts')->assertJsonPath('data.0.carrier', 'Moto Express')
            ->assertJsonPath('data.0.method', 'wave')->assertJsonPath('data.0.lines.0.order_reference', '#1001');

        // Course déjà reversée, ou d'un autre transporteur : refusée
        $this->storeApi($this->store, 'POST', "carriers/{$id}/payouts", ['shipment_ids' => [$first->id, $second->id], 'amount' => 63000])
            ->assertStatus(422)->assertJsonValidationErrors('shipment_ids');
        $this->storeApi($this->store, 'POST', "carriers/{$id}/payouts", ['shipment_ids' => [$second->id], 'amount' => 31500])
            ->assertCreated()->assertJsonPath('data.status', 'matched');
        $this->storeApi($this->store, 'GET', 'carrier-balances')->assertJsonPath('total', 0);

        // Réservé à qui gère les transporteurs
        $cashier = $this->addMember($this->store, User::factory()->create(), Role::Cashier);
        $this->actingAs($cashier);
        $this->storeApi($this->store, 'GET', 'carrier-balances')->assertForbidden();
    }

    public function test_manual_carrier_by_whatsapp(): void
    {
        $id = $this->storeApi($this->store, 'POST', 'carriers/manual', ['name' => 'Moto Express', 'phone' => '0102030405'])->assertCreated()->json('data.id');
        $order = $this->confirmedOrder();
        $shipment = $this->ship($order, CarrierConnection::find($id));

        $message = $this->storeApi($this->store, 'GET', "shipments/{$shipment->id}/message")->assertOk();
        $this->assertStringContainsString('#1001-1', $message->json('data.text'));
        $this->assertStringContainsString('À encaisser : 31 500 F', $message->json('data.text'));
        $this->assertStringStartsWith('https://wa.me/2250102030405?text=', $message->json('data.whatsapp_url'));

        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/status", ['status' => 'in_transit'])->assertOk();
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/status", ['status' => 'delivered', 'collected_amount' => 31500])->assertOk();
        $this->assertSame([OrderStatus::Completed, 0], [$order->fresh()->status, $order->fresh()->amountDue()]);
        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/status", ['status' => 'returned'])->assertStatus(422);
    }

    public function test_rules_and_permissions(): void
    {
        $connection = $this->connect();
        $pending = app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => $this->place('Angré')->id, 'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);
        $this->storeApi($this->store, 'POST', "orders/{$pending->id}/shipments", ['carrier_connection_id' => $connection->id])
            ->assertStatus(422)->assertJsonPath('message', 'Seule une commande confirmée peut être confiée à la livraison.');

        $order = $this->confirmedOrder();
        $this->ship($order, $connection);
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/shipments", ['carrier_connection_id' => $connection->id])
            ->assertStatus(422)->assertJsonPath('message', 'Cette commande a déjà une livraison en cours.');

        // Transporteur qui refuse : la tentative n'est pas gardée
        $this->stub([self::API.'/orders' => Http::response(['message' => 'Données invalides', 'errors' => ['delivery_zone_id' => ['Zone inactive.']]], 422)]);
        $other = $this->confirmedOrder('Yopougon');
        $this->storeApi($this->store, 'POST', "orders/{$other->id}/shipments", ['carrier_connection_id' => $connection->id])
            ->assertStatus(422)->assertJsonPath('message', 'Zone inactive.');
        $this->assertSame(0, Shipment::where('order_id', $other->id)->count());

        $agent = $this->addMember($this->store, User::factory()->create(), Role::ConfirmationAgent);
        $this->logout();
        $this->actingAs($agent);
        $this->storeApi($this->store, 'GET', 'carriers')->assertForbidden();
        $this->storeApi($this->store, 'POST', "orders/{$other->id}/shipments", ['carrier_connection_id' => $connection->id])->assertForbidden();
    }

    public function test_stock_in_the_carrier_warehouse(): void
    {
        $connection = $this->connect();
        $connection->forceFill(['scopes' => [...$connection->scopes, 'stock:read']])->save();
        $this->robe->variants[0]->forceFill(['sku' => 'robe-s'])->save();
        $sku = 'ROBE-S';
        $this->stub([
            self::API.'/hubs' => Http::response(['data' => [['id' => 4, 'name' => 'Entrepôt Treichville', 'zone_id' => 9]]]),
            self::API.'/products*' => Http::response(['data' => [
                ['id' => 55, 'sku' => $sku, 'name' => 'Robe wax S', 'levels' => [['hub_id' => 4, 'on_hand' => 8, 'available' => 7], ['hub_id' => null, 'on_hand' => 2, 'available' => 2]]],
                ['id' => 56, 'sku' => 'INCONNU', 'name' => 'Autre', 'levels' => []],
            ], 'meta' => ['last_page' => 1]]),
        ]);

        $locationId = $this->storeApi($this->store, 'POST', "carriers/{$connection->id}/hubs", ['remote_hub_id' => '4'])->assertCreated()->json('data.id');
        $this->storeApi($this->store, 'POST', "carriers/{$connection->id}/hubs", ['remote_hub_id' => '99'])->assertStatus(422);

        $stock = $this->storeApi($this->store, 'GET', "carriers/{$connection->id}/stock")->assertOk();
        $stock->assertJsonPath('data.hubs.0.location_id', $locationId)
            ->assertJsonPath('data.products.0.variant_id', $this->robe->variants[0]->id)
            ->assertJsonPath('data.products.0.remote_on_hand', 8)
            ->assertJsonPath('data.products.0.local_on_hand', 0)
            ->assertJsonPath('data.products.0.difference', 8)
            ->assertJsonPath('data.products.1.variant_id', null);

        // Expédition depuis l'entrepôt du transporteur : course préparée chez lui, avec ses identifiants produits
        $order = $this->confirmedOrder();
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/shipments", ['carrier_connection_id' => $connection->id, 'origin_location_id' => $locationId])->assertCreated();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/orders' && $r->method() === 'POST'
            && $r['pickup_hub_id'] === 4 && $r['items'][0]['product_id'] === 55 && ! isset($r['pickup_address']));
    }
}
