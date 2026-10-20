<?php

namespace Tests\Feature\Shipping;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\CarrierConnection;
use App\Models\Courier;
use App\Models\CourierPayRule;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Shipping\DeliveryAdvisor;
use App\Services\Shipping\ShipmentDesk;
use Tests\TestCase;

class AssignmentTest extends TestCase
{
    private Store $store;

    private Product $vase;

    private Courier $koffi;

    private CarrierConnection $moto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->vase = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Vase', 'variants' => [['price' => 15000, 'on_hand' => 20]]]);
        $this->actingAs($this->store->owner);

        $rule = CourierPayRule::create(['store_id' => $this->store->id, 'name' => 'Course', 'type' => 'per_delivery', 'amount' => 1500]);
        $this->koffi = Courier::create(['store_id' => $this->store->id, 'name' => 'Koffi', 'phone' => '+2250102030405', 'status' => 'active', 'is_available' => true, 'pay_rule_id' => $rule->id]);
        $this->koffi->zones()->attach($this->zone()->id);
        $this->moto = CarrierConnection::findOrFail($this->storeApi($this->store, 'POST', 'carriers/manual', ['name' => 'Moto Express', 'phone' => '0708091011'])->assertCreated()->json('data.id'));
    }

    private function zone(): DeliveryZone
    {
        return DeliveryZone::forStore($this->store->id)->sole();
    }

    private function order(): Order
    {
        return app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $this->vase->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);
    }

    public function test_comparator_and_rules(): void
    {
        $order = $this->order();
        $quotes = $this->storeApi($this->store, 'GET', "orders/{$order->id}/shipping-quotes")->assertOk();
        $quotes->assertJsonPath('couriers.0.name', 'Koffi')->assertJsonPath('couriers.0.cost', 1500)->assertJsonPath('couriers.0.stats.shipments', 0)
            ->assertJsonPath('data.0.name', 'Moto Express')->assertJsonPath('suggestion.name', 'Koffi')->assertJsonPath('suggestion.reason', 'Suggestion par défaut')
            ->assertJsonPath('assignment_mode', 'suggest');

        // Colis fragile → transporteur choisi ; règle d'abord désactivée
        $rule = $this->storeApi($this->store, 'POST', 'shipping-rules', ['name' => 'Fragile', 'action' => 'carrier', 'conditions' => ['is_fragile' => true]])
            ->assertStatus(422)->assertJsonValidationErrors('carrier_connection_id');
        $rule = $this->storeApi($this->store, 'POST', 'shipping-rules', ['name' => 'Fragile', 'action' => 'carrier', 'carrier_connection_id' => $this->moto->id, 'conditions' => ['is_fragile' => true]])
            ->assertCreated()->json('data.id');
        $this->assertSame('Koffi', app(DeliveryAdvisor::class)->suggest($order)['name']);
        $this->vase->forceFill(['is_fragile' => true])->save();
        $this->assertSame(['carrier', 'Moto Express', 'Règle « Fragile »'], array_values(array_intersect_key(app(DeliveryAdvisor::class)->suggest($order->fresh()), array_flip(['type', 'name', 'reason']))));

        // Une règle « à la main » placée avant : pas de suggestion
        $manual = $this->storeApi($this->store, 'POST', 'shipping-rules', ['name' => 'Gros montant', 'action' => 'manual', 'conditions' => ['min_amount' => 10000]])->assertCreated()->json('data.id');
        $this->storeApi($this->store, 'PATCH', 'shipping-rules', ['order' => [$manual, $rule]])->assertOk()->assertJsonPath('data.0.name', 'Gros montant');
        $this->assertNull(app(DeliveryAdvisor::class)->suggest($order->fresh()));
        $this->storeApi($this->store, 'DELETE', "shipping-rules/{$manual}")->assertOk();

        // Livreur indisponible, pas de règle qui corresponde : le moins cher parmi les transporteurs
        $this->vase->forceFill(['is_fragile' => false])->save();
        $this->koffi->update(['is_available' => false]);
        $this->assertSame('Moto Express', app(DeliveryAdvisor::class)->suggest($order->fresh())['name']);
    }

    public function test_automatic_assignment_on_confirmation(): void
    {
        $this->storeApi($this->store, 'PATCH', 'shipping-rules', ['assignment_mode' => 'auto'])->assertOk()->assertJsonPath('assignment_mode', 'auto');

        $order = $this->order()->fresh(['shipments', 'events']);
        $this->assertSame(OrderStatus::Processing, $order->status);
        $this->assertSame($this->koffi->id, $order->shipments->sole()->courier_id);
        $this->assertTrue($order->events->contains(fn ($e) => $e->type === 'auto_assigned' && $e->data['to'] === 'Koffi'));

        // Pas de livreur ni de transporteur utilisable : l'échec est noté, la commande reste confirmée
        $this->koffi->update(['is_available' => false]);
        $this->moto->update(['status' => 'disabled']);
        $other = $this->order()->fresh('shipments');
        $this->assertSame([OrderStatus::Confirmed, 0], [$other->status, $other->shipments->count()]);
    }

    public function test_delivery_chosen_when_the_order_is_entered(): void
    {
        $angre = Place::whereNull('store_id')->where('name', 'Angré')->value('id');
        $yopougon = Place::whereNull('store_id')->where('name', 'Yopougon')->value('id');
        $items = [['variant_id' => $this->vase->variants[0]->id, 'quantity' => 1]];

        // Entreprise sans API : sans grille, elle livre partout à un prix non renseigné
        $options = $this->storeApi($this->store, 'POST', 'orders/delivery-options', ['place_id' => $angre, 'items' => $items])->assertOk();
        $options->assertJsonPath('data.zone.fee', $this->zone()->fee)->assertJsonPath('data.couriers.0.name', 'Koffi')->assertJsonPath('data.couriers.0.covers_zone', true)
            ->assertJsonPath('data.carriers.0.fee', null)->assertJsonPath('data.carriers.0.covered', true);

        // Grille de tarifs : « Cocody » à 2 000 F couvre Angré, pas Yopougon
        $this->storeApi($this->store, 'POST', "carriers/{$this->moto->id}/tariff-zones", ['name' => 'Cocody', 'fee' => 2000])->assertCreated();
        $this->storeApi($this->store, 'POST', 'orders/delivery-options', ['place_id' => $angre, 'items' => $items])
            ->assertJsonPath('data.carriers.0.fee', 2000)->assertJsonPath('data.carriers.0.zone', 'Cocody')->assertJsonPath('data.carriers.0.covered', true);
        $this->storeApi($this->store, 'POST', 'orders/delivery-options', ['place_id' => $yopougon, 'items' => $items])
            ->assertJsonPath('data.carriers.0.covered', false)->assertJsonPath('data.carriers.0.fee', null);
        // Recherche du quartier : seulement ceux que l'entreprise dessert, avec son tarif ; sinon ceux de la boutique
        $found = $this->storeApi($this->store, 'GET', "orders/delivery-options/places?carrier_connection_id={$this->moto->id}&search=angre")->assertOk()->json('data');
        $this->assertSame(['Angré, Cocody', 'Cocody', 2000], [$found[0]['label'], $found[0]['zone'], $found[0]['fee']]);
        $this->assertSame([], $this->storeApi($this->store, 'GET', "orders/delivery-options/places?carrier_connection_id={$this->moto->id}&search=yopougon")->json('data'));
        $this->assertNotEmpty($this->storeApi($this->store, 'GET', "orders/delivery-options/places?carrier_connection_id={$this->moto->id}")->json('data'));
        $own = $this->storeApi($this->store, 'GET', 'orders/delivery-options/places?search=angre')->json('data.0');
        $this->assertSame(['Angré, Cocody', $this->zone()->fee], [$own['label'], $own['fee']]);
        $tariffs = $this->storeApi($this->store, 'GET', "orders/delivery-options/carriers/{$this->moto->id}")->assertOk()->json('data');
        $this->assertSame([['Cocody', 2000]], array_map(fn ($z) => [$z['label'], $z['fee']], $tariffs['zones']));
        $this->assertContains('Angré, Cocody', $tariffs['zones'][0]['places']);

        // Commande confirmée à la saisie avec l'entreprise choisie : course demandée tout de suite, au tarif affiché
        $payload = ['source' => 'whatsapp', 'customer_phone' => '0506070809', 'delivery_method' => 'delivery', 'shipping_place_id' => $angre, 'items' => $items];
        $id = $this->storeApi($this->store, 'POST', 'orders', $payload + ['confirmed' => true, 'planned_carrier_connection_id' => $this->moto->id, 'shipping_fee' => 2000])
            ->assertCreated()->json('data.id');
        $this->storeApi($this->store, 'GET', "orders/{$id}")->assertJsonPath('data.planned_carrier.name', 'Moto Express');
        $order = Order::with(['shipments', 'events'])->findOrFail($id);
        $this->assertSame([2000, 17000, $this->moto->id], [$order->shipping_fee, $order->total, $order->shipments->sole()->carrier_connection_id]);
        $this->assertTrue($order->events->contains(fn ($e) => $e->type === 'auto_assigned' && $e->data['reason'] === 'Choisi à la saisie'));

        // Livreur choisi, commande à confirmer : la mission part à la confirmation
        $id = $this->storeApi($this->store, 'POST', 'orders', $payload + ['planned_courier_id' => $this->koffi->id])->assertCreated()->json('data.id');
        $this->assertCount(0, Order::findOrFail($id)->shipments);
        $this->storeApi($this->store, 'POST', "orders/{$id}/confirmations", ['outcome' => 'confirmed', 'channel' => 'call'])->assertOk();
        $this->assertSame($this->koffi->id, Order::findOrFail($id)->shipments->sole()->courier_id);
        $this->storeApi($this->store, 'POST', 'orders/delivery-options', ['place_id' => $angre])->assertJsonPath('data.recent.0', "courier:{$this->koffi->id}")
            ->assertJsonPath('data.recent.1', "carrier:{$this->moto->id}");

        // Pas les deux à la fois, pas de livreur inactif
        $this->storeApi($this->store, 'POST', 'orders', $payload + ['planned_courier_id' => $this->koffi->id, 'planned_carrier_connection_id' => $this->moto->id])
            ->assertStatus(422)->assertJsonValidationErrors('planned_courier_id');
        $this->koffi->update(['status' => 'inactive']);
        $this->storeApi($this->store, 'POST', 'orders', $payload + ['planned_courier_id' => $this->koffi->id])->assertStatus(422)->assertJsonValidationErrors('planned_courier_id');
    }

    public function test_history_stats(): void
    {
        $desk = app(ShipmentDesk::class);
        foreach ([ShipmentStatus::Delivered, ShipmentStatus::Delivered, ShipmentStatus::Returned] as $status) {
            $shipment = $desk->assignCourier($this->order(), $this->koffi, [], $this->store->owner);
            $shipment->forceFill(['status' => $status, 'requested_at' => now()->subHours(5), 'delivered_at' => $status === ShipmentStatus::Delivered ? now()->subHour() : null])->save();
        }

        $stats = app(DeliveryAdvisor::class)->stats($this->store->id, 'courier_id', $this->koffi->id, $this->zone()->id);
        $this->assertSame(['shipments' => 3, 'success_rate' => 66.7, 'avg_hours' => 4.0, 'scope' => 'zone'], $stats);
    }
}
