<?php

namespace Tests\Feature\Couriers;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierPayRule;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Place;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CourierTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        // Offre Pro : jusqu'à 5 livreurs (l'offre gratuite n'en permet qu'un)
        Subscription::create(['store_id' => $this->store->id, 'plan_id' => Plan::where('code', 'pro')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, [
            'title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 5]],
        ]);
        $this->actingAs($this->store->owner);
    }

    private function zone(): DeliveryZone
    {
        return DeliveryZone::forStore($this->store->id)->sole();
    }

    /**
     * Livreur ajouté puis inscrit par son lien d'invitation (nouveau compte, code SMS).
     */
    private function onboardCourier(string $phone = '0102030405', string $name = 'Koffi', array $extra = []): array
    {
        $created = $this->storeApi($this->store, 'POST', 'couriers', ['name' => $name, 'phone' => $phone, 'zone_ids' => [$this->zone()->id], ...$extra])->assertCreated();
        $token = basename($created->json('invitation_url'));

        $this->logout();
        $start = $this->storeApi($this->store, 'POST', "invitations/{$token}/accept", ['name' => $name, 'password' => 'motdepasse'])->assertStatus(202)->json();
        $this->storeApi($this->store, 'POST', "verifications/{$start['verification']['token']}/confirm", ['code' => $start['demo_code']])
            ->assertOk()->assertJsonPath('redirect', '/livreur');

        $courier = Courier::findOrFail($created->json('data.id'));
        $user = $courier->user;
        $this->logout();
        $this->actingAs($this->store->owner);

        return [$courier, $user];
    }

    private function order(): Order
    {
        return app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '+2250506070809',
            'delivery_method' => 'delivery', 'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
            'shipping_address' => 'Cité Arcades', 'confirmed' => true,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 2]],
        ], $this->store->owner);
    }

    private function assign(Order $order, Courier $courier): Shipment
    {
        $id = $this->storeApi($this->store, 'POST', "orders/{$order->id}/shipments", ['courier_id' => $courier->id])->assertCreated()->json('data.id');

        return Shipment::findOrFail($id);
    }

    private function asCourier(User $user): void
    {
        $this->logout();
        $this->actingAs($user);
    }

    private function step(Shipment $shipment, array $data): TestResponse
    {
        return $this->storeApi($this->store, 'POST', "courier/missions/{$shipment->id}/steps", $data);
    }

    private function stock(): array
    {
        $level = StockLevel::where('variant_id', $this->robe->variants[0]->id)->first();

        return [$level->on_hand, $level->reserved];
    }

    public function test_a_courier_is_invited_and_lands_on_the_courier_app(): void
    {
        [$courier, $user] = $this->onboardCourier();

        $this->assertSame(['active', $user->id], [$courier->status, $courier->user_id]);
        $this->assertSame([$this->zone()->id], $courier->zones->pluck('id')->all());

        $this->asCourier($user);
        $this->storeApi($this->store, 'GET', 'courier/me')->assertOk()->assertJsonPath('data.name', 'Koffi')->assertJsonPath('data.cash_held', 0);
        $this->storeApi($this->store, 'GET', 'auth/me')->assertOk()->assertJsonPath('data.role.value', 'courier')->assertJsonPath('data.permissions', []);
        // Le livreur n'accède pas à la gestion
        $this->storeApi($this->store, 'GET', 'orders')->assertForbidden();
        $this->storeApi($this->store, 'GET', 'couriers')->assertForbidden();

        $this->storeApi($this->store, 'PATCH', 'courier/availability', ['is_available' => false])->assertOk();
        $this->assertFalse($courier->fresh()->is_available);
    }

    public function test_the_free_plan_allows_one_courier(): void
    {
        Subscription::query()->delete();
        $this->onboardCourier();
        $this->storeApi($this->store, 'POST', 'couriers', ['name' => 'Yao', 'phone' => '0102030499'])
            ->assertStatus(422)->assertJsonPath('message', 'Votre offre Gratuit est limitée à 1 livreurs. Passez à une offre supérieure pour en ajouter.');
    }

    public function test_a_team_member_who_delivers_is_linked_without_invitation(): void
    {
        $manager = $this->addMember($this->store, User::factory()->create(['phone' => '+2250707070707']), Role::Manager);

        $this->storeApi($this->store, 'POST', 'couriers', ['name' => 'Gérant', 'phone' => '07 07 07 07 07'])->assertCreated()
            ->assertJsonPath('invitation_url', null)->assertJsonPath('data.status', 'active');
        $this->storeApi($this->store, 'POST', 'couriers', ['name' => 'Doublon', 'phone' => '0707070707'])->assertStatus(422);

        $this->asCourier($manager);
        $this->storeApi($this->store, 'GET', 'courier/me')->assertOk();
        $this->storeApi($this->store, 'GET', 'orders')->assertOk(); // garde la gestion
    }

    public function test_full_delivery_with_code_photo_cash_and_earnings(): void
    {
        $this->store->forceFill(['require_delivery_code' => true])->save();
        $rule = CourierPayRule::create(['store_id' => $this->store->id, 'name' => 'Par course', 'type' => 'per_delivery', 'amount' => 1000, 'failed_attempt_amount' => 300]);
        [$courier, $user] = $this->onboardCourier(extra: ['pay_rule_id' => $rule->id]);
        [$other, $otherUser] = $this->onboardCourier('0102030499', 'Yao');

        $order = $this->order();
        $quotes = $this->storeApi($this->store, 'GET', "orders/{$order->id}/shipping-quotes")->assertOk();
        $this->assertSame($courier->id, $quotes->json('couriers.0.id'));
        $this->assertTrue($quotes->json('couriers.0.covers_zone'));

        $shipment = $this->assign($order, $courier);
        $this->assertSame(['awaiting_pickup', OrderStatus::Processing], [$shipment->status->value, $order->fresh()->status]);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $shipment->delivery_code);
        $this->assertSame('courier_mission', DatabaseNotification::where('notifiable_id', $user->id)->first()->data['kind']);

        // Le client voit le code sur sa page de suivi
        $this->get($this->storeUrl($this->store, '/suivi/'.$order->tracking_token))->assertSee($shipment->delivery_code)->assertSee('livreur Koffi');

        // Un autre livreur ne voit pas la mission
        $this->asCourier($otherUser);
        $this->storeApi($this->store, 'GET', 'courier/missions')->assertJsonCount(0, 'data');
        $this->step($shipment, ['status' => 'in_transit'])->assertNotFound();

        $this->asCourier($user);
        $this->storeApi($this->store, 'GET', 'courier/missions')->assertJsonPath('data.0.reference', '#1001-1');
        $this->storeApi($this->store, 'GET', "courier/missions/{$shipment->id}")->assertOk()
            ->assertJsonPath('data.cod_amount', 31500)->assertJsonPath('data.requires_code', true)
            ->assertJsonPath('data.next_steps', ['in_transit'])->assertJsonMissingPath('data.delivery_code');

        $this->step($shipment, ['status' => 'delivered'])->assertStatus(422);
        $this->step($shipment, ['status' => 'in_transit'])->assertOk();
        $this->assertSame([OrderStatus::Shipped, [3, 0]], [$order->fresh()->status, $this->stock()]);
        $this->step($shipment, ['status' => 'out_for_delivery'])->assertOk();

        $this->step($shipment, ['status' => 'delivered', 'code' => '0000'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->step($shipment, ['status' => 'delivered', 'code' => $shipment->delivery_code, 'payment_method' => 'cash',
            'photo' => UploadedFile::fake()->image('colis.jpg')])->assertOk()->assertJsonPath('data.status', 'delivered');

        $order->refresh();
        $this->assertSame([OrderStatus::Completed, 0, 'cod_cash'], [$order->status, $order->amountDue(), $order->payments()->first()->kind->value]);
        $this->assertSame(31500, CashCollection::where('courier_id', $courier->id)->sum('amount'));
        $this->assertSame(1000, (int) CourierEarning::where('courier_id', $courier->id)->sum('amount'));
        Storage::disk('local')->assertExists($shipment->fresh()->proof_photo_path);

        $this->storeApi($this->store, 'GET', 'courier/me')->assertJsonPath('data.cash_held', 31500)->assertJsonPath('data.unpaid', 1000)
            ->assertJsonPath('data.today.delivered', 1);

        // La gestion voit la preuve
        $this->asCourier($this->store->owner);
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.shipments.0.courier.name', 'Koffi')
            ->assertJsonPath('data.shipments.0.proof_photo_url', "/api/v1/shipments/{$shipment->id}/proof");
        $this->storeApi($this->store, 'GET', "shipments/{$shipment->id}/proof")->assertOk();
    }

    public function test_failed_attempt_rescheduling_and_return_to_the_shop(): void
    {
        $rule = CourierPayRule::create(['store_id' => $this->store->id, 'name' => 'Par course', 'type' => 'per_delivery', 'amount' => 1000, 'failed_attempt_amount' => 300]);
        [$courier, $user] = $this->onboardCourier(extra: ['pay_rule_id' => $rule->id]);
        $order = $this->order();
        $shipment = $this->assign($order, $courier);

        $this->asCourier($user);
        $this->step($shipment, ['status' => 'in_transit'])->assertOk();
        $this->step($shipment, ['status' => 'failed'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->step($shipment, ['status' => 'failed', 'reason' => 'Client absent', 'rescheduled_to' => today()->addDay()->toDateString()])
            ->assertOk()->assertJsonPath('data.status', 'rescheduled')->assertJsonPath('data.attempts_count', 1);
        $this->assertContains('shipment_rescheduled', DatabaseNotification::all()->pluck('data.kind'));

        $this->step($shipment, ['status' => 'out_for_delivery'])->assertOk();
        $this->step($shipment, ['status' => 'failed', 'reason' => 'Refus du client'])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->step($shipment, ['status' => 'returning'])->assertOk();
        $this->assertSame(600, (int) CourierEarning::where('courier_id', $courier->id)->sum('amount'));

        // Le gérant réceptionne le colis : remise en stock
        $this->asCourier($this->store->owner);
        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/status", ['status' => 'returned'])->assertOk();
        $this->assertSame([OrderStatus::Returned, [5, 0]], [$order->fresh()->status, $this->stock()]);
    }

    public function test_reassignment_and_cancellation(): void
    {
        [$koffi, $koffiUser] = $this->onboardCourier();
        [$yao, $yaoUser] = $this->onboardCourier('0102030499', 'Yao');
        $order = $this->order();
        $shipment = $this->assign($order, $koffi);

        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/assign", ['courier_id' => $yao->id])->assertOk()->assertJsonPath('data.courier.name', 'Yao');
        $this->asCourier($koffiUser);
        $this->storeApi($this->store, 'GET', 'courier/missions')->assertJsonCount(0, 'data');
        $this->asCourier($yaoUser);
        $this->storeApi($this->store, 'GET', 'courier/missions')->assertJsonCount(1, 'data');

        // Désactivation impossible tant qu'il a une mission
        $this->asCourier($this->store->owner);
        $this->storeApi($this->store, 'POST', "couriers/{$yao->id}/deactivate")->assertStatus(422);

        $this->storeApi($this->store, 'POST', "shipments/{$shipment->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);

        $this->storeApi($this->store, 'POST', "couriers/{$yao->id}/deactivate")->assertOk();
        $this->asCourier($yaoUser);
        $this->storeApi($this->store, 'GET', 'courier/me')->assertForbidden();
    }

    public function test_cash_desk_and_payroll(): void
    {
        $rule = CourierPayRule::create(['store_id' => $this->store->id, 'name' => 'Par zone', 'type' => 'per_zone', 'amount' => 800, 'zone_amounts' => [(string) $this->zone()->id => 1200]]);
        [$courier, $user] = $this->onboardCourier(extra: ['pay_rule_id' => $rule->id, 'cash_limit' => 20000]);

        foreach ([$this->order(), $this->order()] as $order) {
            $shipment = $this->assign($order, $courier);
            $this->asCourier($user);
            $this->step($shipment, ['status' => 'in_transit'])->assertOk();
            $this->step($shipment, ['status' => 'delivered', 'payment_method' => 'cash'])->assertOk();
            $this->asCourier($this->store->owner);
        }

        $this->storeApi($this->store, 'GET', 'cash')->assertOk()
            ->assertJsonPath('data.0.held', 31500 * 2)->assertJsonPath('data.0.over_limit', true)->assertJsonPath('data.0.collections_count', 2);
        $this->storeApi($this->store, 'GET', "cash/couriers/{$courier->id}")->assertJsonCount(2, 'data')->assertJsonPath('data.0.reference', '#1001');

        // Versement avec 500 F de manquant
        $this->storeApi($this->store, 'POST', "cash/couriers/{$courier->id}/remittances", ['received_amount' => 62500, 'method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.expected_amount', 63000)->assertJsonPath('data.difference', -500);
        $this->assertSame(0, $courier->fresh()->cashHeld());
        $this->storeApi($this->store, 'POST', "cash/couriers/{$courier->id}/remittances", ['received_amount' => 100, 'method' => 'cash'])->assertStatus(422);
        $this->storeApi($this->store, 'GET', 'cash')->assertJsonPath('remittances.0.difference', -500);

        // Paie : 2 × 1 200 F (zone), prime, avance
        $this->storeApi($this->store, 'POST', "couriers/{$courier->id}/earnings", ['type' => 'bonus', 'amount' => 2000, 'note' => 'Semaine chargée'])->assertCreated();
        $this->storeApi($this->store, 'POST', "couriers/{$courier->id}/earnings", ['type' => 'advance', 'amount' => 1500])->assertCreated()->assertJsonPath('data.amount', -1500);
        $this->storeApi($this->store, 'GET', "couriers/{$courier->id}/earnings")->assertJsonPath('unpaid', 2400 + 2000 - 1500)->assertJsonCount(4, 'data');

        $payout = $this->storeApi($this->store, 'POST', "couriers/{$courier->id}/payouts")->assertCreated()->assertJsonPath('data.total', 2900)->json('data');
        $this->storeApi($this->store, 'POST', "couriers/{$courier->id}/payouts")->assertStatus(422);
        $this->storeApi($this->store, 'POST', "courier-payouts/{$payout['id']}/cancel")->assertOk();
        $payout = $this->storeApi($this->store, 'POST', "couriers/{$courier->id}/payouts")->assertCreated()->json('data');
        $this->storeApi($this->store, 'POST', "courier-payouts/{$payout['id']}/pay", ['method' => 'wave', 'reference' => 'WV-1'])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->storeApi($this->store, 'POST', "courier-payouts/{$payout['id']}/cancel")->assertStatus(422);

        $this->asCourier($user);
        $this->storeApi($this->store, 'GET', 'courier/earnings')->assertJsonPath('data.unpaid', 0);
        $this->storeApi($this->store, 'GET', 'courier/cash')->assertJsonPath('data.held', 0)->assertJsonPath('data.remittances.0.difference', -500);

        // Caissier : caisse oui, livreurs non
        $cashier = $this->addMember($this->store, User::factory()->create(), Role::Cashier);
        $this->asCourier($cashier);
        $this->storeApi($this->store, 'GET', 'cash')->assertOk();
        $this->storeApi($this->store, 'GET', 'couriers')->assertForbidden();
    }
}
