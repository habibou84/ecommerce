<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SlugHistory;
use Tests\TestCase;

class StoreSettingsTest extends TestCase
{
    public function test_settings_are_updated_with_normalized_phones(): void
    {
        $store = $this->createStore();
        $this->actingAs($store->owner);

        $this->storeApi($store, 'PATCH', 'store', ['name' => 'Awa Mode', 'tagline' => 'Mode à Abidjan', 'whatsapp_phone' => '07 01 02 03 04'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Awa Mode')
            ->assertJsonPath('data.whatsapp_phone', '+2250701020304');

        $this->storeApi($store, 'PATCH', 'store', ['email' => 'pas-un-email'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_settings_require_the_store_permission(): void
    {
        $store = $this->createStore();
        $this->actingAs($this->addMember($store, null, Role::StockKeeper));

        $this->storeApi($store, 'GET', 'store')->assertOk();
        $this->storeApi($store, 'PATCH', 'store', ['name' => 'X'])->assertForbidden();
    }

    public function test_changing_the_address_redirects_the_old_one(): void
    {
        $store = $this->createStore(['slug' => 'ancienne']);
        $this->createStore(['slug' => 'prise']);
        $this->actingAs($store->owner);

        $this->storeApi($store, 'PUT', 'store/slug', ['slug' => 'prise'])->assertStatus(422);

        $response = $this->storeApi($store, 'PUT', 'store/slug', ['slug' => 'nouvelle'])->assertOk();
        $this->assertSame('nouvelle', $store->fresh()->slug);
        $this->assertStringStartsWith('http://nouvelle.localhost/auth/relais', $response->json('redirect'));
        $this->assertDatabaseHas('slug_history', ['old_slug' => 'ancienne', 'store_id' => $store->id]);

        $this->get('http://ancienne.localhost/')->assertStatus(301)->assertRedirect('http://nouvelle.localhost/');

        // L'ancienne adresse reste réservée à la boutique, qui peut la reprendre
        $other = $this->createStore();
        $this->actingAs($other->owner);
        $this->storeApi($other, 'PUT', 'store/slug', ['slug' => 'ancienne'])->assertStatus(422);

        $this->actingAs($store->owner);
        $this->apiOn('http://nouvelle.localhost/', 'PUT', 'store/slug', ['slug' => 'ancienne'])->assertOk();
        $this->assertFalse(SlugHistory::where('old_slug', 'ancienne')->exists());
        $this->assertTrue(SlugHistory::where('old_slug', 'nouvelle')->exists());
    }
}
