<?php

namespace Tests\Feature\Marketing;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Place;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\WhatsApp\CustomerMessenger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
    }

    private function customer(string $name, string $phone, array $attributes): Customer
    {
        return Customer::forceCreate($attributes + ['store_id' => $this->store->id, 'name' => $name, 'phone' => $phone, 'orders_count' => 1, 'delivered_count' => 1, 'last_order_at' => now()->subDays(90)]);
    }

    public function test_win_back_old_customers_on_whatsapp(): void
    {
        $awa = $this->customer('Awa Koné', '+2250506070801', ['accepts_marketing' => true]);
        $this->customer('Binta', '+2250506070802', ['accepts_marketing' => false]);
        $this->customer('Chantal', '+2250506070803', ['accepts_marketing' => true, 'last_order_at' => now()->subDays(10)]);
        $this->customer('Djeneba', '+2250506070804', ['accepts_marketing' => false, 'marketing_opt_out_at' => now()->subDay()]);
        $this->customer('Esther', '+2250506070805', ['accepts_marketing' => true, 'blacklisted_at' => now()]);

        $inactive = ['type' => 'inactive', 'days' => 60];
        $this->storeApi($this->store, 'POST', 'campaigns/preview', ['audience' => $inactive])->assertOk()
            ->assertJsonPath('data.recipients', 1)->assertJsonPath('data.estimated_cost', 15)->assertJsonPath('data.buyers_without_consent', 1);
        $this->storeApi($this->store, 'POST', 'campaigns/preview', ['audience' => $inactive + ['include_buyers' => true]])->assertOk()
            ->assertJsonPath('data.recipients', 2);

        $id = $this->storeApi($this->store, 'POST', 'campaigns', [
            'title' => 'Collection wax', 'message' => "la nouvelle collection wax est arrivée,\n -10 % jusqu'à dimanche.", 'audience' => $inactive + ['include_buyers' => true],
        ])->assertCreated()->assertJsonPath('data.message', "la nouvelle collection wax est arrivée, -10 % jusqu'à dimanche.")->json('data.id');

        // Sans le modèle « Nouveautés », rien ne part
        $this->storeApi($this->store, 'POST', "campaigns/{$id}/send")->assertStatus(422);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.'.Str::random(6)]]])]);
        $account = WhatsappAccount::create(['store_id' => $this->store->id, 'phone_number_id' => '123', 'access_token' => 'tok', 'app_secret' => 's', 'verify_token' => Str::random(20),
            'webhook_uuid' => (string) Str::uuid(), 'templates' => ['campaign' => 'jm_nouveautes'], 'automations' => [], 'status' => 'active']);
        $this->storeApi($this->store, 'POST', "campaigns/{$id}/send")->assertOk()
            ->assertJsonPath('data.status', 'sent')->assertJsonPath('data.recipients', 2)->assertJsonPath('data.estimated_cost', 30);
        $this->storeApi($this->store, 'POST', "campaigns/{$id}/send")->assertStatus(422);

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => ($r['template']['name'] ?? null) === 'jm_nouveautes' && $r['to'] === '2250506070801'
            && array_column($r['template']['components'][0]['parameters'], 'text') === ['Awa', 'Awa Mode', "la nouvelle collection wax est arrivée, -10 % jusqu'à dimanche.", $this->store->url('/')]);

        // Pas deux relances dans la même semaine
        $this->storeApi($this->store, 'POST', 'campaigns/preview', ['audience' => ['type' => 'all', 'include_buyers' => true]])->assertJsonPath('data.recipients', 1);

        // Awa commande après la relance : comptée dans les résultats
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 5]]]);
        app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Awa Koné', 'customer_phone' => '+2250506070801', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $robe->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);
        $results = $this->storeApi($this->store, 'GET', 'campaigns')->assertOk()->json('data.0.results');
        $this->assertSame(1, $results['orders']);
        $this->assertGreaterThanOrEqual(15000, $results['orders_amount']);

        // Awa répond STOP : plus de relance ; le marchand peut la réinscrire si elle redonne son accord
        app(CustomerMessenger::class)->handleInbound($account, ['entry' => [['changes' => [['value' => [
            'contacts' => [['wa_id' => '2250506070801', 'profile' => ['name' => 'Awa']]],
            'messages' => [['from' => '2250506070801', 'id' => 'wamid.stop', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'Stop']]],
        ]]]]]]);
        $awa->refresh();
        $this->assertFalse($awa->accepts_marketing);
        $this->assertNotNull($awa->marketing_opt_out_at);
        $this->storeApi($this->store, 'PUT', "customers/{$awa->id}", ['accepts_marketing' => true])->assertOk();
        $this->assertNull($awa->refresh()->marketing_opt_out_at);
    }

    public function test_buyers_of_a_product(): void
    {
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 5]]]);
        $desk = app(OrderDesk::class);
        $order = $desk->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $robe->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);
        $audience = ['type' => 'product', 'product_id' => $robe->id, 'include_buyers' => true];
        $this->storeApi($this->store, 'POST', 'campaigns/preview', ['audience' => $audience])->assertJsonPath('data.recipients', 0);

        $desk->transition($desk->transition($order, OrderStatus::Shipped, $this->store->owner), OrderStatus::Completed, $this->store->owner);
        $this->storeApi($this->store, 'POST', 'campaigns/preview', ['audience' => $audience])->assertJsonPath('data.recipients', 1)->assertJsonPath('data.sample', ['Fatou']);
        $this->storeApi($this->store, 'POST', 'campaigns', ['title' => 'x', 'message' => 'un message assez long', 'audience' => ['type' => 'product', 'product_id' => 999999]])->assertStatus(422);
    }
}
