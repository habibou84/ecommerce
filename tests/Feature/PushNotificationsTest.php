<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DeviceToken;
use App\Models\Place;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    private Store $store;

    private string $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->credentials = tempnam(sys_get_temp_dir(), 'firebase');
        file_put_contents($this->credentials, json_encode(['type' => 'service_account', 'project_id' => 'jibiat-test', 'client_email' => 'push@jibiat-test.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['services.firebase.credentials' => $this->credentials]);

        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => fn (HttpRequest $r) => $r['message']['token'] === 'tok-desinstalle'
                ? Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                : Http::response(['name' => 'projects/jibiat-test/messages/1']),
        ]);

        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentials);
        parent::tearDown();
    }

    private function order(User $actor): void
    {
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 5]]]);
        app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
            'items' => [['variant_id' => $robe->variants[0]->id, 'quantity' => 1]],
        ], $actor);
    }

    public function test_devices_receive_the_team_alerts(): void
    {
        $owner = $this->store->owner;
        $agent = $this->addMember($this->store, User::factory()->create(), Role::ConfirmationAgent);

        // Le téléphone du propriétaire s'enregistre ; un même téléphone repris par une autre personne change de main
        $this->actingAs($owner);
        $this->storeApi($this->store, 'POST', 'devices', ['token' => 'tok-proprio', 'platform' => 'android'])->assertOk();
        $this->storeApi($this->store, 'POST', 'devices', ['token' => 'tok-proprio', 'platform' => 'android'])->assertOk();
        $this->storeApi($this->store, 'POST', 'devices', ['token' => 'tok-desinstalle', 'platform' => 'android'])->assertOk();
        $this->storeApi($this->store, 'POST', 'devices', ['token' => 'x', 'platform' => 'windows'])->assertStatus(422);
        $this->assertSame(2, DeviceToken::where('user_id', $owner->id)->count());

        // Commande saisie par l'agent : le propriétaire est prévenu sur son téléphone (pas l'auteur)
        $this->order($agent);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://oauth2.googleapis.com/token'
            && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && count(explode('.', $r['assertion'])) === 3);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/jibiat-test/messages:send'
            && $r->hasHeader('Authorization', 'Bearer ya29.test') && $r['message']['token'] === 'tok-proprio'
            && $r['message']['notification']['title'] === 'Nouvelle commande #1001'
            && str_starts_with($r['message']['data']['url'], $this->storeUrl($this->store, '/gestion/commandes/')));
        // Application désinstallée : l'adresse est oubliée
        $this->assertSame(['tok-proprio'], DeviceToken::pluck('token')->all());

        // Déconnexion du téléphone
        $this->storeApi($this->store, 'DELETE', 'devices', ['token' => 'tok-proprio'])->assertOk();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_nothing_is_sent_without_firebase_or_without_device(): void
    {
        $agent = $this->addMember($this->store, User::factory()->create(), Role::ConfirmationAgent);
        $this->order($agent); // aucun téléphone enregistré
        Http::assertNothingSent();

        $this->actingAs($this->store->owner);
        $this->storeApi($this->store, 'POST', 'devices', ['token' => 'tok-proprio', 'platform' => 'android'])->assertOk();
        config(['services.firebase.credentials' => null]);
        $this->order($agent);
        Http::assertNothingSent();
    }
}
