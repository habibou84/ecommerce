<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DeviceToken;
use App\Models\Place;
use App\Models\PushSubscription;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Push\WebPush;
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

    public function test_browsers_receive_the_alerts_by_web_push(): void
    {
        config(['services.firebase.credentials' => null, 'services.webpush' => WebPush::generateVapidKeys() + ['subject' => 'mailto:contact@jibiat-marchand.com']]);
        Http::fake([
            'https://updates.push.services.mozilla.com/wpush/expire' => Http::response('', 410),
            'https://updates.push.services.mozilla.com/*' => Http::response('', 201),
        ]);
        $devices = [];
        $browser = function () use (&$devices): array {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $ec = openssl_pkey_get_details($key)['ec'];
            $public = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
            $devices[] = [$key, $public, $auth = random_bytes(16)];

            return ['p256dh' => WebPush::b64($public), 'auth' => WebPush::b64($auth)];
        };
        // Déchiffrement « aes128gcm » comme le fait le navigateur (RFC 8291)
        $decrypt = function (string $body, array $device): ?array {
            [$key, $public, $auth] = $device;
            $salt = substr($body, 0, 16);
            $server = substr($body, 21, ord($body[20]));
            $cipher = substr($body, 21 + ord($body[20]));
            $prkKey = hash_hmac('sha256', openssl_pkey_derive(WebPush::publicKeyPem($server), $key, 32), $auth, true);
            $prk = hash_hmac('sha256', substr(hash_hmac('sha256', "WebPush: info\0".$public.$server."\x01", $prkKey, true), 0, 32), $salt, true);
            $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16),
                OPENSSL_RAW_DATA, substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12), substr($cipher, -16));

            return $plain === false ? null : json_decode(substr($plain, 0, -1), true);
        };
        $owner = $this->store->owner;
        $agent = $this->addMember($this->store, User::factory()->create(), Role::ConfirmationAgent);

        // L'ordinateur et le téléphone (application installée depuis le navigateur) du propriétaire s'abonnent
        $this->actingAs($owner);
        $this->storeApi($this->store, 'GET', 'push')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.public_key', config('services.webpush.public_key'));
        $this->storeApi($this->store, 'POST', 'push/subscriptions', ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/ordinateur', 'keys' => $browser()])->assertCreated();
        $this->storeApi($this->store, 'POST', 'push/subscriptions', ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/expire', 'keys' => $browser()])->assertCreated();
        $this->storeApi($this->store, 'POST', 'push/subscriptions', ['endpoint' => 'https://exemple.ci/x', 'keys' => ['p256dh' => 'court', 'auth' => 'court']])->assertStatus(422);
        $this->assertSame(2, PushSubscription::where('user_id', $owner->id)->count());

        // Commande saisie par l'agent : le navigateur du propriétaire reçoit l'alerte chiffrée et signée
        $this->order($agent);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://updates.push.services.mozilla.com/wpush/v2/ordinateur'
            && $r->hasHeader('Content-Encoding', 'aes128gcm') && str_starts_with($r->header('Authorization')[0], 'vapid t=')
            && ($decrypt($r->body(), $devices[0])['title'] ?? null) === 'Nouvelle commande #1001'
            && str_starts_with($decrypt($r->body(), $devices[0])['url'], $this->storeUrl($this->store, '/gestion/commandes/')));
        // Abonnement expiré : oublié
        $this->assertSame(['https://updates.push.services.mozilla.com/wpush/v2/ordinateur'], PushSubscription::pluck('endpoint')->all());

        // Essai, puis désabonnement à la déconnexion
        $this->storeApi($this->store, 'POST', 'push/test')->assertOk()->assertJsonPath('data.devices', 1);
        $this->storeApi($this->store, 'DELETE', 'push/subscriptions', ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/ordinateur'])->assertNoContent();
        $this->assertSame(0, PushSubscription::count());
        $this->storeApi($this->store, 'POST', 'push/test')->assertStatus(422);

        // Seuls les services de notifications des navigateurs sont appelés (pas une adresse quelconque, ni ses redirections)
        $this->assertTrue(WebPush::allowedEndpoint('https://fcm.googleapis.com/fcm/send/abc'));
        $this->assertTrue(WebPush::allowedEndpoint('https://web.push.apple.com/abc'));
        $this->assertFalse(WebPush::allowedEndpoint('https://attaquant.tld/r'));
        $this->assertFalse(WebPush::allowedEndpoint('https://fcm.googleapis.com.attaquant.tld/r'));
        $this->assertFalse(WebPush::allowedEndpoint('http://fcm.googleapis.com/x'));
        $this->assertFalse(WebPush::allowedEndpoint('https://fcm.googleapis.com:8443/x'));
    }
}
