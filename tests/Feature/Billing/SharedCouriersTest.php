<?php

namespace Tests\Feature\Billing;

use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierPayout;
use App\Models\CourierPayRule;
use App\Models\CourierRemittance;
use App\Models\Place;
use App\Models\Plan;
use App\Models\Scopes\StoreScope;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounts\StoreRoles;
use App\Services\Catalog\CatalogWriter;
use App\Services\Couriers\SharedCouriers;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Shipping\ShipmentDesk;
use Tests\TestCase;

class SharedCouriersTest extends TestCase
{
    private Store $mode;

    private Store $kids;

    private User $koffi;

    private Courier $courier;

    private int $phone = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mode = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->mode->currentSubscription()->first()->update(['status' => SubscriptionStatus::Expired]);
        $this->mode->subscriptions()->create(['plan_id' => Plan::where('code', 'business')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $this->actingAs($this->mode->owner);
        $this->storeApi($this->mode, 'POST', 'my-stores', ['name' => 'Awa Kids', 'slug' => 'awa-kids'])->assertCreated();
        $this->kids = Store::where('slug', 'awa-kids')->sole();

        $this->koffi = User::factory()->create(['name' => 'Koffi', 'phone' => '+2250102030405']);
        $this->addMember($this->mode, $this->koffi, Role::Courier);
        $rule = CourierPayRule::create(['store_id' => $this->mode->id, 'name' => 'Course', 'type' => 'per_delivery', 'amount' => 1000]);
        $this->courier = Courier::create(['store_id' => $this->mode->id, 'user_id' => $this->koffi->id, 'name' => 'Koffi', 'phone' => $this->koffi->phone, 'status' => 'active', 'pay_rule_id' => $rule->id]);
    }

    /**
     * Commande de la boutique confiée à sa fiche de Koffi.
     */
    private function mission(Store $store, int $price): Shipment
    {
        return SharedCouriers::within($store, function () use ($store, $price) {
            app(Places::class)->provisionDefaultZone($store);
            $product = app(CatalogWriter::class)->save($store, null, ['title' => 'Article '.$price, 'variants' => [['price' => $price, 'on_hand' => 10]]]);
            $order = app(OrderDesk::class)->create($store, [
                'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+22505060708'.$this->phone++, 'delivery_method' => 'delivery', 'confirmed' => true,
                'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
                'items' => [['variant_id' => $product->variants[0]->id, 'quantity' => 1]],
            ], $store->owner);

            return app(ShipmentDesk::class)->assignCourier($order, Courier::forStore($store->id)->where('user_id', $this->koffi->id)->sole(), [], $store->owner);
        });
    }

    public function test_a_courier_works_for_several_stores(): void
    {
        $people = $this->storeApi($this->mode, 'GET', 'my-stores/couriers')->assertOk()->json('data.people');
        $this->assertSame(['Koffi', ['Awa Mode'], ['Awa Kids']], [$people[0]['name'], array_column($people[0]['stores'], 'store_name'), array_column($people[0]['other_stores'], 'name')]);

        // Partagé avec Awa Kids : fiche, règle de paie et accès à l'application dans cette boutique
        $people = $this->storeApi($this->mode, 'POST', "my-stores/couriers/{$this->courier->id}/stores/{$this->kids->id}")->assertOk()->json('data.people');
        $this->assertSame(['Awa Mode', 'Awa Kids'], array_column($people[0]['stores'], 'store_name'));
        $kidsCourier = Courier::forStore($this->kids->id)->sole();
        $this->assertSame([$this->koffi->id, 'active', 'Course'], [$kidsCourier->user_id, $kidsCourier->status, CourierPayRule::forStore($this->kids->id)->find($kidsCourier->pay_rule_id)->name]);
        $this->assertSame(Role::Courier, app(StoreRoles::class)->roleOf($this->koffi, $this->kids));

        // Une mission dans chaque boutique : l'application de Koffi, ouverte sur Awa Mode, montre les deux
        $modeMission = $this->mission($this->mode, 10000);
        $kidsMission = $this->mission($this->kids, 6000);
        $this->actingAs($this->koffi);
        $missions = $this->storeApi($this->mode, 'GET', 'courier/missions')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Awa Mode', 'Awa Kids'], array_column($missions, 'store'));
        $this->storeApi($this->mode, 'GET', "courier/missions/{$kidsMission->id}")->assertOk()->assertJsonPath('data.store', 'Awa Kids');

        // Il livre la mission d'Awa Kids depuis l'application d'Awa Mode : tout est enregistré chez Awa Kids
        foreach ([$modeMission, $kidsMission] as $mission) {
            foreach (['in_transit', 'out_for_delivery'] as $step) {
                $this->storeApi($this->mode, 'POST', "courier/missions/{$mission->id}/steps", ['status' => $step])->assertOk();
            }
            $this->storeApi($this->mode, 'POST', "courier/missions/{$mission->id}/steps", ['status' => 'delivered', 'payment_method' => 'cash'])->assertOk();
        }
        $kidsCash = CashCollection::forStore($this->kids->id)->sole();
        $this->assertSame([$kidsCourier->id, $kidsMission->fresh()->cod_amount], [$kidsCash->courier_id, $kidsCash->amount]);
        $total = $modeMission->fresh()->cod_amount + $kidsMission->fresh()->cod_amount;

        $me = $this->storeApi($this->kids, 'GET', 'courier/me')->assertOk()->json('data');
        $this->assertSame([$total, 2000, 2], [$me['cash_held'], $me['unpaid'], $me['today']['delivered']]);
        $this->assertCount(2, $this->storeApi($this->kids, 'GET', 'courier/cash')->json('data.collections'));
        $this->storeApi($this->kids, 'PATCH', 'courier/availability', ['is_available' => false])->assertOk();
        $this->assertSame(0, Courier::withoutGlobalScope(StoreScope::class)->where('user_id', $this->koffi->id)->where('is_available', true)->count());

        // Un seul versement, avec 1 000 F de manquant, réparti entre les deux boutiques
        $this->actingAs($this->mode->owner);
        $remittances = $this->storeApi($this->kids, 'POST', "my-stores/couriers/{$kidsCourier->id}/remittances", ['received_amount' => $total - 1000, 'method' => 'cash'])
            ->assertCreated()->json('data');
        $this->assertCount(2, $remittances);
        $this->assertSame($total - 1000, array_sum(array_column($remittances, 'received_amount')));
        $this->assertSame(-1000, array_sum(array_column($remittances, 'difference')));
        $this->assertSame(0, CashCollection::withoutGlobalScope(StoreScope::class)->whereNull('remittance_id')->count());
        $this->assertSame(1, CourierRemittance::forStore($this->kids->id)->count());

        // Paie des deux boutiques réglée en une fois
        $payouts = $this->storeApi($this->mode, 'POST', "my-stores/couriers/{$this->courier->id}/payouts", ['method' => 'wave'])->assertCreated()->json('data');
        $this->assertSame([1000, 1000], array_column($payouts, 'total'));
        $this->assertSame(2, CourierPayout::withoutGlobalScope(StoreScope::class)->where('status', 'paid')->count());
        $this->storeApi($this->mode, 'POST', "my-stores/couriers/{$this->courier->id}/payouts", ['method' => 'wave'])->assertStatus(422);

        // Il ne travaille plus pour Awa Kids ; Awa Mode ne change pas
        $this->storeApi($this->mode, 'DELETE', "my-stores/couriers/{$this->courier->id}/stores/{$this->kids->id}")->assertOk();
        $this->assertSame(['inactive', 'active'], [$kidsCourier->fresh()->status, $this->courier->fresh()->status]);
        $this->actingAs($this->koffi);
        $this->assertSame(['Awa Mode'], array_column($this->storeApi($this->mode, 'GET', 'courier/me')->json('data.stores'), 'name'));
    }

    public function test_only_couriers_with_an_account_and_stores_of_the_group(): void
    {
        $invited = Courier::create(['store_id' => $this->mode->id, 'name' => 'Yao', 'phone' => '+2250708091011', 'status' => 'invited']);
        $this->storeApi($this->mode, 'POST', "my-stores/couriers/{$invited->id}/stores/{$this->kids->id}")->assertStatus(422);

        $other = $this->createStore(['slug' => 'voisin']);
        $this->storeApi($this->mode, 'POST', "my-stores/couriers/{$this->courier->id}/stores/{$other->id}")->assertNotFound();
        $foreign = Courier::create(['store_id' => $other->id, 'name' => 'Ali', 'phone' => '+2250708091012', 'status' => 'active']);
        $this->storeApi($this->mode, 'POST', "my-stores/couriers/{$foreign->id}/stores/{$this->kids->id}")->assertNotFound();
        $this->assertSame(0, Courier::forStore($this->kids->id)->count());
    }
}
