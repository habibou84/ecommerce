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
