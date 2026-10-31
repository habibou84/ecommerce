<?php

namespace Tests\Feature\Platform;

use App\Models\AdminAction;
use App\Models\Conversation;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\Features;
use Tests\TestCase;

/**
 * Console › Fonctions : masquée (invisible, le serveur refuse), test (boutiques choisies) ou active.
 */
class FeaturesTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    public function test_meta_features_are_hidden_by_default_and_live_is_on(): void
    {
        PlatformSetting::where('key', 'like', 'feature.%')->delete();
        $features = app(Features::class);
        $this->assertSame('hidden', $features->state('social_meta'));
        $this->assertSame('hidden', $features->state('whatsapp_catalog'));
        $this->assertSame('on', $features->state('live'));
    }

    public function test_hidden_test_and_on_from_the_console(): void
    {
        $store = $this->createStore(['slug' => 'awa']);
        $other = $this->createStore(['slug' => 'kids']);
        $admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $features = app(Features::class);

        // Console : changement noté dans le journal, fonction inconnue refusée
        $console = fn (string $method, string $path, array $data = []) => $this->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
        $start = $console('POST', 'auth/login', ['phone' => '07 01 02 03 04', 'password' => 'motdepasse', 'channel' => 'phone'])->assertStatus(202);
        $console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])->assertOk();
        $console('PUT', 'features/meta_messages', ['state' => 'hidden'])->assertOk()->assertJsonFragment(['key' => 'meta_messages', 'state' => 'hidden']);
        $this->assertSame(['from' => 'on', 'to' => 'hidden'], AdminAction::where('action', 'feature.meta_messages')->sole()->data);
        $console('PUT', 'features/test-stores', ['store_ids' => [$store->id]])->assertOk()->assertJsonPath('data.test_stores.0.slug', 'awa');
        $console('PUT', 'features/inconnue', ['state' => 'on'])->assertNotFound();
        $console('PUT', 'features/live', ['state' => 'bientot'])->assertStatus(422);
    }

    public function test_what_a_store_sees(): void
    {
        $store = $this->createStore(['slug' => 'awa']);
        $other = $this->createStore(['slug' => 'kids']);
        $features = app(Features::class);
        $features->setTestStores([$store->id]);

        // Masquée : rien dans le profil, appels refusés, conversations Messenger cachées
        $features->set('social_meta', 'hidden');
        $features->set('meta_messages', 'hidden');
        Conversation::create(['store_id' => $store->id, 'channel' => 'messenger', 'contact' => 'psid-1', 'contact_name' => 'Fatou', 'last_inbound_at' => now(), 'last_message_at' => now()]);
        Conversation::create(['store_id' => $store->id, 'channel' => 'whatsapp', 'contact' => '+2250700000009', 'contact_name' => 'Awa', 'last_inbound_at' => now(), 'last_message_at' => now()]);

        $this->actingAs($store->owner);
        $this->storeApi($store, 'GET', 'auth/me')->assertJsonPath('data.features.social_meta', false)->assertJsonPath('data.features.live', true);
        $this->storeApi($store, 'GET', 'social')->assertOk()->assertJsonPath('accounts.0.network', 'tiktok');
        $this->storeApi($store, 'POST', 'social/connect/meta')->assertStatus(422);
        $this->storeApi($store, 'GET', 'conversations')->assertJsonCount(1, 'data')->assertJsonPath('data.0.channel', 'whatsapp');

        // Tous les réseaux éteints : la page refuse
        $features->set('social_tiktok', 'hidden');
        $this->storeApi($store, 'GET', 'social')->assertForbidden()->assertJsonPath('code', 'feature_off');

        // Test : seulement pour la boutique choisie
        $features->set('meta_messages', 'test');
        $this->storeApi($store, 'GET', 'conversations')->assertJsonCount(2, 'data');
        $this->storeApi($store, 'GET', 'auth/me')->assertJsonPath('data.features.meta_messages', true);
        $this->actingAs($other->owner);
        $this->storeApi($other, 'GET', 'auth/me')->assertJsonPath('data.features.meta_messages', false);

        // Inscription fermée : la création est refusée
        $features->set('signup', 'hidden');
        $this->apiOn($this->platformUrl(), 'POST', 'signup', [])->assertForbidden();
    }
}
