<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\AccessoryOrder;
use App\Models\AdminAction;
use App\Models\Store;
use App\Models\User;
use App\Notifications\MaterielOrderNotice;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Matériel de la plateforme : produits (stock de la plateforme ou partenaire à commission), réglages (paiement,
 * livraison), commande depuis la gestion, paiement Wave annoncé puis confirmé, suivi, annulation et stock.
 */
class MaterielTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private Store $store;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['platform.billing.wave_link' => 'https://pay.wave.com/m/M_jibiat/c/ci/']);
        $this->admin = User::factory()->create(['phone' => '+2250701020304']);
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->withSession(['console_admin' => $this->admin->id, 'console_since' => now()->timestamp])
            ->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    private function shop(string $method, string $path, array $data = []): TestResponse
    {
        $this->actingAs($this->store->owner);

        return $this->storeApi($this->store, $method, $path, $data);
    }

    /**
     * @return array{printer: int, ring: int}
     */
    private function openShop(): array
    {
        $this->console('POST', 'materiel/products', ['name' => 'Imprimante d\'étiquettes', 'category' => 'impression', 'price' => 45000, 'cost_price' => 35000,
            'stock' => 3, 'supplier' => 'own', 'is_active' => true])->assertOk();
        $this->console('POST', 'materiel/products', ['name' => 'Pack Live', 'category' => 'live', 'price' => 25000, 'supplier' => 'partner',
            'partner_name' => 'Studio Plus', 'partner_phone' => '0505050505', 'commission_type' => 'percent', 'commission_value' => 10, 'is_active' => true])->assertOk();
        $this->console('POST', 'materiel/products', ['name' => 'Partenaire sans commission', 'category' => 'live', 'price' => 1000, 'supplier' => 'partner', 'is_active' => true])->assertStatus(422);

        // Boutique fermée tant qu'elle n'est pas ouverte dans la console
        $this->shop('GET', 'materiel')->assertOk()->assertJsonPath('data.open', false)->assertJsonPath('data.products', []);
        $this->console('PUT', 'materiel/settings', ['enabled' => true, 'wave' => false, 'cod' => false, 'deliveries' => [['label' => 'Abidjan', 'fee' => 1500, 'address' => true]]])->assertStatus(422);
        $this->console('PUT', 'materiel/settings', ['enabled' => true, 'wave' => true, 'cod' => true, 'deliveries' => [
            ['label' => 'Livraison à Abidjan', 'fee' => 1500, 'note' => '24 à 48 h', 'address' => true],
            ['label' => 'Retrait sur place', 'fee' => 0, 'address' => false],
        ]])->assertOk()->assertJsonPath('data.settings.deliveries.0.code', 'livraison-a-abidjan-1')->assertJsonPath('data.methods.cod', 'Paiement à la livraison');

        $ids = Accessory::pluck('id', 'category');

        return ['printer' => $ids['impression'], 'ring' => $ids['live']];
    }

    public function test_a_merchant_orders_and_pays_at_delivery(): void
    {
        ['printer' => $printer, 'ring' => $ring] = $this->openShop();
        $this->shop('GET', 'auth/me')->assertJsonPath('data.materiel', true);
        $this->shop('GET', 'materiel')->assertJsonPath('data.open', true)->assertJsonCount(2, 'data.products')
            ->assertJsonPath('data.deliveries.1.address', false)->assertJsonMissingPath('data.products.0.cost_price');
        $this->shop('GET', 'materiel/suggest?category=live')->assertJsonPath('data.name', 'Pack Live');

        // Plus que le stock : refusé
        $this->shop('POST', 'materiel/orders', ['items' => [['id' => $printer, 'quantity' => 5]], 'delivery' => 'livraison-a-abidjan-1', 'payment' => 'cod',
            'contact_name' => 'Awa', 'contact_phone' => '0707070707', 'address' => 'Cocody Angré'])->assertStatus(422)->assertJsonPath('errors.items.0', 'Il reste seulement 3 « Imprimante d\'étiquettes ».');
        // Livraison à domicile sans adresse : refusé
        $this->shop('POST', 'materiel/orders', ['items' => [['id' => $printer, 'quantity' => 1]], 'delivery' => 'livraison-a-abidjan-1', 'payment' => 'cod',
            'contact_name' => 'Awa', 'contact_phone' => '0707070707'])->assertStatus(422);

        $order = $this->shop('POST', 'materiel/orders', ['items' => [['id' => $printer, 'quantity' => 2], ['id' => $ring, 'quantity' => 1]], 'delivery' => 'livraison-a-abidjan-1',
            'payment' => 'cod', 'contact_name' => 'Awa', 'contact_phone' => '0707070707', 'address' => 'Cocody Angré'])->assertCreated()
            ->assertJsonPath('data.total', 2 * 45000 + 25000 + 1500)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.wave_url', null)->json('data');
        $this->assertMatchesRegularExpression('/^MAT-\d{4}-\d{6}$/', $order['number']);
        $this->assertSame(1, Accessory::find($printer)->stock);
        // Marge sur le stock de la plateforme (2 × 10 000) + commission du partenaire (10 % de 25 000)
        $this->assertSame(22500, AccessoryOrder::sole()->earnings);

        // La console fait avancer la commande ; l'e-commerçant est prévenu à chaque étape
        $this->console('GET', 'materiel?status=open')->assertJsonPath('data.orders.0.store.name', 'Awa Mode')->assertJsonPath('data.orders.0.items.1.partner_name', 'Studio Plus');
        foreach (['confirmed', 'preparing', 'shipping', 'delivered'] as $status) {
            $this->console('POST', "materiel/orders/{$order['id']}/status", ['status' => $status])->assertOk();
        }
        Notification::assertSentToTimes($this->store->owner, MaterielOrderNotice::class, 4);
        $sold = AccessoryOrder::sole();
        $this->assertSame(['delivered', 'paid'], [$sold->status, $sold->payment_status]);
        $this->console('GET', 'materiel')->assertJsonPath('data.totals.sales', 116500)->assertJsonPath('data.totals.earnings', 22500);
        $this->console('POST', "materiel/orders/{$order['id']}/status", ['status' => 'cancelled'])->assertStatus(422);
        // Les 4 étapes sont au journal ; l'annulation refusée n'y est pas
        $this->assertSame(4, AdminAction::where('action', 'like', 'materiel_order%')->count());
    }

    public function test_wave_payment_is_announced_then_confirmed_and_a_pending_order_can_be_cancelled(): void
    {
        ['printer' => $printer] = $this->openShop();
        $this->shop('POST', 'materiel/orders', ['items' => [['id' => $printer, 'quantity' => 1]], 'delivery' => 'retrait-sur-place-2', 'payment' => 'wave',
            'contact_name' => 'Awa', 'contact_phone' => '0707070707'])->assertStatus(422)->assertJsonPath('errors.payer_phone.0', 'Indiquez le numéro Wave qui va payer.');
        $order = $this->shop('POST', 'materiel/orders', ['items' => [['id' => $printer, 'quantity' => 1]], 'delivery' => 'retrait-sur-place-2', 'payment' => 'wave',
            'contact_name' => 'Awa', 'contact_phone' => '0707070707', 'payer_phone' => '0708080808'])->assertCreated()
            ->assertJsonPath('data.wave_url', 'https://pay.wave.com/m/M_jibiat/c/ci/?amount=45000')->assertJsonPath('data.address', null)->json('data');

        $this->shop('POST', "materiel/orders/{$order['id']}/paid")->assertOk()->assertJsonPath('data.payment_status', 'claimed')->assertJsonPath('data.wave_url', null);
        $this->console('GET', 'materiel')->assertJsonPath('data.totals.to_check', 1)->assertJsonPath('data.orders.0.payer_phone', '07 08 08 08 08');
        $this->console('POST', "materiel/orders/{$order['id']}/paid", ['paid' => true])->assertOk();
        $this->assertSame('paid', AccessoryOrder::sole()->payment_status);

        // Annulée par l'e-commerçant tant qu'elle n'est pas confirmée : le stock revient
        $this->assertSame(2, Accessory::find($printer)->stock);
        $this->shop('POST', "materiel/orders/{$order['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(3, Accessory::find($printer)->stock);

        // Confirmée : l'e-commerçant ne peut plus annuler lui-même ; une autre boutique ne voit pas ses commandes
        $other = $this->shop('POST', 'materiel/orders', ['items' => [['id' => $printer, 'quantity' => 1]], 'delivery' => 'retrait-sur-place-2', 'payment' => 'cod',
            'contact_name' => 'Awa', 'contact_phone' => '0707070707'])->assertCreated()->json('data.id');
        $this->console('POST', "materiel/orders/{$other}/status", ['status' => 'confirmed'])->assertOk();
        $this->shop('POST', "materiel/orders/{$other}/cancel")->assertStatus(422);
        $koffi = $this->createStore(['slug' => 'koffi']);
        $this->actingAs($koffi->owner);
        $this->storeApi($koffi, 'POST', "materiel/orders/{$other}/cancel")->assertNotFound();
        $this->storeApi($koffi, 'GET', 'materiel')->assertJsonPath('data.orders', []);
    }
}
