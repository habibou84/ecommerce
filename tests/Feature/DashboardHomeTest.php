<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Place;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Accueil de la gestion : ce qui distingue Jibiat Marchand (ventes par canal, boutique en ligne,
 * clientes à relancer, promotions, bénéfice du mois).
 */
class DashboardHomeTest extends TestCase
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

    private function order(string $source): Order
    {
        $id = $this->storeApi($this->store, 'POST', 'orders', [
            'source' => $source,
            'customer_name' => 'Fatou',
            'customer_phone' => '05 06 07 08 '.random_int(10, 99),
            'delivery_method' => 'delivery',
            'shipping_place_id' => Place::where('name', 'Angré')->value('id'),
            'items' => [['variant_id' => $this->variantId, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    public function test_sales_by_channel_shop_relaunch_promotions_and_profit(): void
    {
        $this->order('whatsapp');
        $this->order('whatsapp');
        $this->order('facebook');
        // Commande passée sur la boutique en ligne
        $this->order('whatsapp')->forceFill(['source' => 'online_store'])->save();

        // Panier laissé il y a 2 heures avec un téléphone : à relancer
        $cart = Cart::create(['store_id' => $this->store->id, 'token' => Str::random(40), 'status' => 'active', 'phone' => '+2250708091011', 'last_activity_at' => now()->subHours(2)]);
        $cart->items()->create(['variant_id' => $this->variantId, 'quantity' => 1]);
        // Panier de moins d'une heure : pas encore
        $fresh = Cart::create(['store_id' => $this->store->id, 'token' => Str::random(40), 'status' => 'active', 'phone' => '+2250708091012', 'last_activity_at' => now()->subMinutes(10)]);
        $fresh->items()->create(['variant_id' => $this->variantId, 'quantity' => 1]);

        // Clientes fidèles : une à relancer (2 livraisons, rien depuis 40 jours), une récente, une sur liste noire
        $loyal = fn (array $extra) => Customer::create(['store_id' => $this->store->id, 'name' => 'Cliente', 'phone' => '+22507'.random_int(10000000, 99999999)])
            ->forceFill(['delivered_count' => 3] + $extra)->save();
        $loyal(['last_order_at' => now()->subDays(40)]);
        $loyal(['last_order_at' => now()->subDays(5)]);
        $loyal(['last_order_at' => now()->subDays(40), 'blacklisted_at' => now()]);

        Discount::create(['store_id' => $this->store->id, 'code' => 'WAX', 'title' => 'Robes wax', 'type' => 'percentage', 'value' => 15, 'ends_at' => now()->addDays(2)]);
        Discount::create(['store_id' => $this->store->id, 'code' => 'FINI', 'title' => 'Terminée', 'type' => 'percentage', 'value' => 10, 'ends_at' => now()->subDay()]);

        $data = $this->storeApi($this->store, 'GET', 'dashboard')->assertOk()->json('data');

        // Le canal qui vend le plus en premier
        $this->assertSame(['whatsapp', 'facebook', 'online_store'], array_column($data['channels'], 'source'));
        $this->assertSame([2, 'WhatsApp'], [$data['channels'][0]['count'], $data['channels'][0]['label']]);
        $this->assertSame($data['orders']['today_amount'], array_sum(array_column($data['channels'], 'amount')));

        $this->assertSame([1, 1, 1], [$data['shop']['today'], $data['shop']['month'], $data['shop']['abandoned']]);
        $this->assertStringContainsString($this->store->slug, $data['shop']['url']);

        $this->assertSame(1, $data['to_relaunch']);
        $this->assertSame(1, $data['promotions']['active']);
        $this->assertSame('Robes wax', $data['promotions']['ending']['title']);

        // Le propriétaire voit les finances : bénéfice du mois
        $this->assertArrayHasKey('profit', $data['profit']);

        // Le filtre de la liste des clients donne les mêmes clientes que l'accueil
        $this->assertCount(1, $this->storeApi($this->store, 'GET', 'customers?filter=to_relaunch')->assertOk()->json('data'));
    }

    public function test_shop_and_profit_follow_permissions(): void
    {
        // Préparateur de stock : ni boutique, ni bénéfice, ni clientes (les ventes du jour, oui : il voit les commandes)
        $this->actingAs($this->addMember($this->store, null, Role::StockKeeper));
        $data = $this->storeApi($this->store, 'GET', 'dashboard')->assertOk()->json('data');

        $this->assertArrayNotHasKey('shop', $data);
        $this->assertArrayNotHasKey('profit', $data);
        $this->assertArrayNotHasKey('to_relaunch', $data);
    }
}
