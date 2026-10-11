<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\PaymentAccount;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Storefront\CartService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private int $sent = 0;

    private ?string $metaError = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/v20.0/*' => function () {
            if ($this->metaError) {
                return Http::response(['error' => ['message' => $this->metaError]], 400);
            }
            $this->sent++;

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.'.$this->sent]]]);
        }]);

        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    private function connect(array $automations = ['order_confirmation' => true, 'order_shipped' => true, 'order_delivered' => true, 'payment_link' => true, 'cart_recovery' => true]): WhatsappAccount
    {
        $this->storeApi($this->store, 'PUT', 'whatsapp', [
            'phone_number_id' => '1098765', 'waba_id' => '2233', 'display_phone' => '+2250700000001', 'access_token' => 'EAAG-token', 'app_secret' => 'app-secret',
            'templates' => ['order_confirmation' => 'confirmation_commande', 'order_shipped' => 'commande_expediee', 'order_delivered' => 'merci_livraison', 'payment_link' => 'lien_paiement', 'cart_recovery' => 'panier_abandonne'],
            'automations' => $automations,
        ])->assertOk();

        return WhatsappAccount::sole();
    }

    private function order(bool $confirmed = false): Order
    {
        return app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => $confirmed,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 2]],
        ], $this->store->owner);
    }

    private function inbound(WhatsappAccount $account, array $value, ?string $secret = null): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2233', 'changes' => [['field' => 'messages', 'value' => $value]]]]]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp/'.$account->webhook_uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? 'app-secret'),
        ], $body);
    }

    public function test_connection_and_meta_webhook_verification(): void
    {
        $account = $this->connect();
        $show = $this->storeApi($this->store, 'GET', 'whatsapp')->assertOk()
            ->assertJsonPath('data.account.templates.order_confirmation', 'confirmation_commande')->assertJsonMissingPath('data.account.access_token');
        $this->assertSame('EAAG-token', $account->access_token);

        $url = 'http://api.localhost/webhooks/whatsapp/'.$account->webhook_uuid;
        $this->assertSame($url, $show->json('data.account.webhook_url'));
        $this->get($url.'?hub.mode=subscribe&hub.verify_token='.$account->verify_token.'&hub.challenge=123456')->assertOk()->assertSeeText('123456');
        $this->get($url.'?hub.mode=subscribe&hub.verify_token=faux&hub.challenge=1')->assertForbidden();

        $this->storeApi($this->store, 'PUT', 'whatsapp', ['templates' => ['order_shipped' => 'Nom Invalide']])->assertStatus(422);
        // Mise à jour sans ressaisir le jeton
        $this->storeApi($this->store, 'PUT', 'whatsapp', ['access_token' => '', 'automations' => ['order_shipped' => false]])->assertOk();
        $this->assertSame('EAAG-token', $account->fresh()->access_token);
        $this->assertFalse($account->fresh()->automations['order_shipped']);
    }

    public function test_confirmation_request_answered_by_button(): void
    {
        $account = $this->connect();
        $order = $this->order();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://graph.facebook.com/v20.0/1098765/messages'
            && $r->hasHeader('Authorization', 'Bearer EAAG-token')
            && $r['to'] === '2250506070809' && $r['template']['name'] === 'confirmation_commande'
            && $r['template']['components'][0]['parameters'][1]['text'] === '#1001'
            && $r['template']['components'][1]['parameters'][0]['payload'] === "confirm:{$order->id}"
            && $r['template']['components'][2]['parameters'][0]['payload'] === "cancel:{$order->id}");
        $message = OutboundMessage::sole();
        $this->assertSame(['sent', 'wamid.1', 'order_confirmation'], [$message->status, $message->provider_message_id, $message->event]);

        // Statuts de Meta
        $this->inbound($account, ['statuses' => [['id' => 'wamid.1', 'status' => 'read']]])->assertOk();
        $this->assertSame('read', $message->fresh()->status);

        // Signature fausse, ou bouton venant d'un autre numéro : sans effet
        $this->inbound($account, ['messages' => [['from' => '2250506070809', 'type' => 'button', 'button' => ['payload' => "confirm:{$order->id}"]]]], 'faux')->assertStatus(401);
        $this->inbound($account, ['messages' => [['from' => '2250102030405', 'type' => 'button', 'button' => ['payload' => "confirm:{$order->id}"]]]])->assertOk();
        $this->assertSame(OrderStatus::PendingConfirmation, $order->fresh()->status);

        $this->inbound($account, ['messages' => [['from' => '2250506070809', 'type' => 'button', 'button' => ['payload' => "confirm:{$order->id}", 'text' => 'Confirmer']]]])->assertOk();
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame('whatsapp', $order->confirmations()->first()->channel);

        $refused = $this->order();
        $this->inbound($account, ['messages' => [['from' => '2250506070809', 'type' => 'button', 'button' => ['payload' => "cancel:{$refused->id}"]]]])->assertOk();
        $this->assertSame(OrderStatus::Cancelled, $refused->fresh()->status);
    }

    public function test_shipping_and_delivery_messages_follow_automations(): void
    {
        $this->connect(['order_shipped' => true, 'order_delivered' => false]);
        $order = $this->order(confirmed: true);
        $this->assertSame(0, OutboundMessage::count()); // déjà confirmée : pas de demande de confirmation

        $order = app(OrderDesk::class)->transition($order, OrderStatus::Shipped, $this->store->owner);
        app(OrderDesk::class)->transition($order, OrderStatus::Completed, $this->store->owner);

        $this->assertSame(['order_shipped'], OutboundMessage::pluck('event')->all());
        Http::assertSent(fn (HttpRequest $r) => ($r['template']['name'] ?? null) === 'commande_expediee'
            && $r['template']['components'][0]['parameters'][2]['text'] === $order->trackingUrl());
    }

    public function test_no_account_means_no_message_and_errors_are_kept(): void
    {
        $this->order();
        $this->assertSame(0, OutboundMessage::count());

        $this->connect();
        $this->metaError = '(#132001) Template name does not exist in the translation';
        $this->order();
        $message = OutboundMessage::sole();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('Template name does not exist', $message->error);
    }

    public function test_payment_link_is_sent_by_whatsapp(): void
    {
        $this->connect(['payment_link' => true]);
        PaymentAccount::create(['store_id' => $this->store->id, 'provider' => 'wave', 'credentials' => ['api_key' => 'k', 'webhook_secret' => 's'], 'status' => 'active'])
            ->routes()->create(['store_id' => $this->store->id, 'method' => 'wave', 'priority' => 1]);
        $order = $this->order(confirmed: true);

        $this->storeApi($this->store, 'POST', "orders/{$order->id}/payment-links")->assertCreated()->assertJsonPath('data.sent_by_whatsapp', true);
        Http::assertSent(fn (HttpRequest $r) => ($r['template']['name'] ?? null) === 'lien_paiement'
            && str_starts_with($r['template']['components'][0]['parameters'][3]['text'], 'http://awa.localhost/payer/'));
    }

    public function test_abandoned_carts_are_recovered(): void
    {
        $this->store->forceFill(['storefront_enabled' => true])->save();
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $token = $this->withCredentials()->withHeaders($headers)
            ->postJson($this->storeUrl($this->store, '/panier/articles'), ['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1])
            ->getCookie(CartService::COOKIE)->getValue();
        $this->withCredentials()->withHeaders($headers)->withCookie(CartService::COOKIE, $token)
            ->postJson($this->storeUrl($this->store, '/commande/contact'), ['name' => 'Aminata Koné', 'phone' => '0708091011'])->assertOk();
        $cart = Cart::sole();

        // Trop récent : pas encore relancé
        $this->connect(['cart_recovery' => true]);
        $this->artisan('carts:recover')->assertSuccessful();
        $this->assertNull($cart->fresh()->recovery_sent_at);

        $this->travel(2)->hours();
        $this->storeApi($this->store, 'GET', 'carts/abandoned')->assertOk()->assertJsonPath('data.0.phone', '07 08 09 10 11')
            ->assertJsonPath('data.0.subtotal', 15000)->assertJsonPath('data.0.items.0', '1 × Robe wax');

        $this->artisan('carts:recover')->assertSuccessful();
        $this->assertNotNull($cart->fresh()->recovery_sent_at);
        Http::assertSent(fn (HttpRequest $r) => ($r['template']['name'] ?? null) === 'panier_abandonne' && $r['to'] === '2250708091011'
            && $r['template']['components'][0]['parameters'][2]['text'] === 'http://awa.localhost/panier/reprendre/'.$cart->token);

        // Une seule relance
        $this->artisan('carts:recover')->assertSuccessful();
        $this->assertSame(1, OutboundMessage::where('event', 'cart_recovery')->count());

        // Le lien rend le panier au client
        $this->get($this->storeUrl($this->store, '/panier/reprendre/'.$cart->token))->assertRedirect('/panier')->assertCookie(CartService::COOKIE);
    }
}
