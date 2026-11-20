<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Courier;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use Tests\TestCase;

class SetupGuideTest extends TestCase
{
    public function test_steps_follow_the_store_data_and_can_be_skipped(): void
    {
        $store = $this->createStore(['name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($store);
        $this->actingAs($store->owner);

        $guide = $this->storeApi($store, 'GET', 'setup-guide')->assertOk();
        $steps = collect($guide->json('data.steps'))->keyBy('key');
        $this->assertSame('mobile_app', $guide->json('data.next'));
        $this->assertFalse($steps['products']['done']);
        $this->assertFalse($steps['mobile_app']['optional']);
        $this->assertStringStartsWith('/videos/etapes/etape-02.mp4?v=', $steps['mobile_app']['video']['video']);
        $this->assertNull($steps['alerts']['video']);
        $this->assertTrue($steps['alerts']['optional']);
        $total = $guide->json('data.total');

        // Faites d'après les données : informations, produit, livreur
        $store->forceFill(['tagline' => 'La mode wax', 'whatsapp_phone' => '+2250102030405'])->save();
        app(CatalogWriter::class)->save($store, null, ['title' => 'Robe', 'variants' => [['price' => 10000, 'on_hand' => 3]]]);
        Courier::create(['store_id' => $store->id, 'name' => 'Koffi', 'phone' => '+2250102030499', 'status' => 'active', 'is_available' => true]);
        $guide = $this->storeApi($store, 'GET', 'setup-guide');
        $steps = collect($guide->json('data.steps'))->keyBy('key');
        $this->assertTrue($steps['store_info']['done'] && $steps['products']['done'] && $steps['deliverers']['done']);
        $this->assertSame(3, $guide->json('data.done'));
        $this->assertSame('mobile_app', $guide->json('data.next'));

        // Notifications activées sur le navigateur (application installée depuis Chrome ou Safari) : étape faite
        PushSubscription::create(['user_id' => $store->owner_id, 'endpoint' => 'https://push.exemple/abc', 'endpoint_hash' => PushSubscription::hashEndpoint('https://push.exemple/abc'), 'p256dh' => 'x', 'auth' => 'y']);
        $guide = $this->storeApi($store, 'GET', 'setup-guide');
        $this->assertSame(4, $guide->json('data.done'));
        $this->assertSame('delivery_zones', $guide->json('data.next'));

        // Passer une étape, puis la reprendre
        $this->storeApi($store, 'PUT', 'setup-guide/delivery_zones', ['skipped' => true])->assertOk()
            ->assertJsonPath('data.next', 'delivery_days');
        $this->assertTrue(collect($this->storeApi($store, 'GET', 'setup-guide')->json('data.steps'))->firstWhere('key', 'delivery_zones')['skipped']);
        $this->storeApi($store, 'PUT', 'setup-guide/delivery_zones', ['skipped' => false])->assertJsonPath('data.next', 'delivery_zones');
        $this->storeApi($store, 'PUT', 'setup-guide/inconnue', ['skipped' => true])->assertNotFound();
        $this->assertSame($total, $guide->json('data.total'));

        // Un agent de confirmation ne voit que ce que son rôle permet
        $agent = User::factory()->create();
        $this->addMember($store, $agent, Role::ConfirmationAgent);
        $this->actingAs($agent);
        $keys = collect($this->storeApi($store, 'GET', 'setup-guide')->json('data.steps'))->pluck('key')->all();
        $this->assertNotContains('store_info', $keys);
        $this->assertContains('mobile_app', $keys);
        $this->storeApi($store, 'PUT', 'setup-guide/store_info', ['skipped' => true])->assertForbidden();
    }

    public function test_welcome_assistant_for_a_new_store_until_finished(): void
    {
        $store = $this->createStore(['name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($store);
        $this->actingAs($store->owner);

        // Nouvelle boutique : l'accueil en trois étapes est proposé, rien n'est encore fait
        $this->storeApi($store, 'GET', 'setup-guide')->assertOk()
            ->assertJsonPath('data.welcome.pending', true)
            ->assertJsonPath('data.welcome.steps', ['delivery' => false, 'product' => false, 'whatsapp' => false]);

        // Tarifs réglés depuis l'assistant, premier produit, WhatsApp relié à Meta : les étapes suivent les données
        $this->storeApi($store, 'PUT', 'delivery-rates', ['base_fee' => 2000, 'expedition_fee' => 1000])->assertOk();
        app(CatalogWriter::class)->save($store, null, ['title' => 'Robe', 'variants' => [['price' => 15000, 'on_hand' => 5]]]);
        $this->storeApi($store, 'GET', 'setup-guide')
            ->assertJsonPath('data.welcome.steps', ['delivery' => true, 'product' => true, 'whatsapp' => false])
            ->assertJsonPath('data.welcome.pending', true);
        WhatsappAccount::create(['store_id' => $store->id, 'phone_number_id' => '1', 'display_phone' => '+225 07 00 00 00 01', 'access_token' => 't', 'status' => 'active']);
        $this->storeApi($store, 'GET', 'setup-guide')
            ->assertJsonPath('data.welcome.steps.whatsapp', true)
            ->assertJsonPath('data.welcome.pending', false);

        // Un agent de confirmation ne voit pas l'accueil et ne peut pas le clore
        $agent = User::factory()->create();
        $this->addMember($store, $agent, Role::ConfirmationAgent);
        $this->actingAs($agent);
        $this->storeApi($store, 'GET', 'setup-guide')->assertJsonPath('data.welcome.pending', false);
        $this->storeApi($store, 'POST', 'setup-guide/welcome')->assertForbidden();
    }

    public function test_welcome_assistant_put_off_or_old_store_is_not_proposed(): void
    {
        $store = $this->createStore(['name' => 'Awa Mode']);
        $this->actingAs($store->owner);

        // « Plus tard » ou terminé : plus proposé
        $this->storeApi($store, 'POST', 'setup-guide/welcome')->assertOk()->assertJsonPath('data.pending', false);
        $this->assertNotEmpty($store->fresh()->setup_guide['welcome_done_at']);
        $this->storeApi($store, 'GET', 'setup-guide')->assertJsonPath('data.welcome.pending', false);

        // Boutique déjà ancienne : pas d'accueil
        $old = $this->createStore(['name' => 'Ancienne']);
        $old->forceFill(['created_at' => now()->subDays(30)])->save();
        $this->actingAs($old->owner);
        $this->storeApi($old, 'GET', 'setup-guide')->assertJsonPath('data.welcome.pending', false);
    }
}
