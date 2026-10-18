<?php

namespace Tests\Feature\Marketing;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Tests\TestCase;

class LoyaltyTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 50]]]);
    }

    private function order(string $phone, string $name, int $quantity = 1, array $extra = []): Order
    {
        $id = $this->storeApi($this->store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_name' => $name, 'customer_phone' => $phone, 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => $quantity]], ...$extra,
        ])->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    private function finish(Order $order, OrderStatus $status = OrderStatus::Completed): void
    {
        $desk = app(OrderDesk::class);
        $desk->transition($desk->transition($order, OrderStatus::Shipped, $this->store->owner), $status, $this->store->owner);
    }

    private function points(string $phone): int
    {
        return Customer::where('phone', $phone)->value('loyalty_points');
    }

    public function test_points_and_referral(): void
    {
        $fatou = '+2250506070809';
        $koffi = '+2250102030405';
        // Programme désactivé : rien n'est gagné
        $this->finish($this->order($fatou, 'Fatou Diallo'));
        $this->assertSame(0, $this->points($fatou));

        $this->storeApi($this->store, 'PUT', 'loyalty', [
            'enabled' => true, 'points_per_1000' => 1, 'point_value' => 50, 'min_points' => 10,
            'referral_enabled' => true, 'referral_discount' => 1000, 'referral_points' => 20,
        ])->assertOk();

        // 2 robes livrées : 30 points
        $first = $this->order($fatou, 'Fatou Diallo', 2);
        $this->finish($first);
        $this->assertSame(30, $this->points($fatou));
        $loyalty = $this->storeApi($this->store, 'GET', 'customers/'.Customer::where('phone', $fatou)->value('id'))->json('data.loyalty');
        $this->assertSame([30, 1500, true, 'earned'], [$loyalty['points'], $loyalty['value'], $loyalty['usable'], $loyalty['history'][0]['type']]);
        $code = $loyalty['referral_code'];
        $this->assertMatchesRegularExpression('/^FATO[A-Z2-9]{4}$/', $code);

        // Page de suivi : points et code à partager
        $this->get($this->store->url('/suivi/'.$first->tracking_token))->assertOk()->assertSee('30 point(s) de fidélité')->assertSee($code)->assertSee(rawurlencode('/?parrain='.$code), false);

        // Koffi, nouveau client, donne le code : 1 000 F de réduction ; Fatou ne peut pas utiliser son propre code
        $referred = $this->order($koffi, 'Koffi', 1, ['referral_code' => strtolower($code)]);
        $this->assertSame([1000, 15000 - 1000 + $referred->shipping_fee], [$referred->referral_discount, $referred->total]);
        $this->assertSame(0, $this->order($fatou, 'Fatou Diallo', 1, ['referral_code' => $code])->referral_discount);
        $this->finish($referred);
        $this->assertSame(14, $this->points($koffi));
        $this->assertSame(50, $this->points($fatou));
        // Deuxième commande de Koffi : plus de réduction de bienvenue
        $this->assertSame(0, $this->order($koffi, 'Koffi', 1, ['referral_code' => $code])->referral_discount);

        // Fatou utilise 20 points (1 000 F) ; pas moins que le minimum ni plus que son solde
        $this->storeApi($this->store, 'POST', 'orders', ['source' => 'whatsapp', 'customer_phone' => $fatou, 'delivery_method' => 'pickup', 'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]], 'reward_points' => 5])
            ->assertStatus(422)->assertJsonValidationErrors(['reward_points']);
        $this->storeApi($this->store, 'POST', 'orders', ['source' => 'whatsapp', 'customer_phone' => $fatou, 'delivery_method' => 'pickup', 'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]], 'reward_points' => 500])
            ->assertStatus(422);
        $withPoints = $this->order($fatou, 'Fatou Diallo', 1, ['reward_points' => 20]);
        $this->assertSame([20, 1000], [$withPoints->points_used, $withPoints->points_discount]);
        $this->assertSame(30, $this->points($fatou));
        // Commande annulée : points rendus
        app(OrderDesk::class)->transition($withPoints, OrderStatus::Cancelled, $this->store->owner);
        $this->assertSame(50, $this->points($fatou));

        // Commande retournée : les points gagnés sont retirés
        $returned = $this->order($koffi, 'Koffi');
        $this->finish($returned, OrderStatus::Returned);
        $this->assertSame(14, $this->points($koffi));

        $stats = $this->storeApi($this->store, 'GET', 'loyalty')->assertOk()->json('data');
        $this->assertSame([2, 64, 1], [$stats['stats']['members'], $stats['stats']['outstanding_points'], $stats['stats']['referrals']]);

        // Ajustement à la main, sans passer sous zéro
        $koffiId = Customer::where('phone', $koffi)->value('id');
        $this->storeApi($this->store, 'POST', "customers/{$koffiId}/loyalty", ['points' => -100])->assertStatus(422);
        $this->storeApi($this->store, 'POST', "customers/{$koffiId}/loyalty", ['points' => 6, 'note' => 'Geste commercial'])->assertOk()->assertJsonPath('data.points', 20);
    }
}
