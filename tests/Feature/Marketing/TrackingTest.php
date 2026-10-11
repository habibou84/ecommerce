<?php

namespace Tests\Feature\Marketing;

use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use App\Services\Storefront\CartService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->store->forceFill(['storefront_enabled' => true])->save();
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    public function test_settings_and_pixels_on_the_storefront(): void
    {
        $this->get($this->storeUrl($this->store, '/produits/'.$this->robe->slug))->assertOk()->assertDontSee('fbevents.js')->assertDontSee('jmTrack');

        $this->storeApi($this->store, 'PUT', 'marketing/tracking', ['meta_pixel_id' => 'abc', 'ga4_id' => 'UA-1'])
            ->assertStatus(422)->assertJsonValidationErrors(['meta_pixel_id', 'ga4_id']);
        $this->storeApi($this->store, 'PUT', 'marketing/tracking', [
            'meta_pixel_id' => '123456789012345', 'tiktok_pixel_id' => 'C4ABCDEF123456789', 'ga4_id' => 'G-ABC123XYZ', 'meta_capi_token' => 'EAAB-secret',
        ])->assertOk()->assertJsonPath('data.has_capi_token', true)->assertJsonMissingPath('data.meta_capi_token');
        $this->assertSame('EAAB-secret', $this->store->fresh()->meta_capi_token);

        $page = $this->get($this->storeUrl($this->store, '/produits/'.$this->robe->slug))->assertOk();
        $page->assertSee('fbevents.js')->assertSee('"123456789012345"', false)->assertSee('analytics.tiktok.com')
            ->assertSee('googletagmanager.com/gtag/js?id=G-ABC123XYZ', false)->assertSee("jmTrack('view_item'", false);

        // Jeton gardé si le champ est laissé vide ; retiré sur demande
        $this->storeApi($this->store, 'PUT', 'marketing/tracking', ['meta_pixel_id' => '123456789012345'])->assertJsonPath('data.has_capi_token', true);
        $this->storeApi($this->store, 'PUT', 'marketing/tracking', ['meta_pixel_id' => '123456789012345', 'remove_capi_token' => true])->assertJsonPath('data.has_capi_token', false);
    }

    public function test_purchase_is_sent_to_the_conversions_api_with_the_pixel_event_id(): void
    {
        $this->store->forceFill(['marketing' => ['meta_pixel_id' => '123456789012345'], 'meta_capi_token' => 'EAAB-secret'])->save();
        $sent = [];
        Http::fake(['graph.facebook.com/*' => function (HttpRequest $request) use (&$sent) {
            $sent[] = $request;

            return Http::response(['events_received' => 1]);
        }]);

        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0 Test'];
        $cart = $this->withCredentials()->withHeaders($headers)
            ->postJson($this->storeUrl($this->store, '/panier/articles?fbclid=IwAR123'), ['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1])
            ->getCookie(CartService::COOKIE)->getValue();
        $redirect = $this->withCredentials()->withHeaders($headers)->withCookie(CartService::COOKIE, $cart)->withUnencryptedCookie('_fbp', 'fb.1.1700000000000.42')
            ->postJson($this->storeUrl($this->store, '/commande'), [
                'name' => 'Fatou Diallo Koné', 'phone' => '0506070809', 'delivery_method' => 'delivery',
                'place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
            ])->assertCreated()->json('redirect');

        $order = Order::latest('id')->first();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('/123456789012345/events?access_token=EAAB-secret', $sent[0]->url());
        $event = $sent[0]['data'][0];
        $this->assertSame(['Purchase', 'order-'.$order->id, $order->total], [$event['event_name'], $event['event_id'], $event['custom_data']['value']]);
        $this->assertSame([hash('sha256', '2250506070809')], $event['user_data']['ph']);
        $this->assertSame([hash('sha256', 'fatou'), hash('sha256', 'koné')], [$event['user_data']['fn'][0], $event['user_data']['ln'][0]]);
        $this->assertSame(['fb.1.1700000000000.42', 'Mozilla/5.0 Test'], [$event['user_data']['fbp'], $event['user_data']['client_user_agent']]);
        $this->assertStringEndsWith('.IwAR123', $event['user_data']['fbc']);
        $this->assertNotNull($order->fresh()->conversion_sent_at);

        // Le pixel envoie l'achat avec le même identifiant (dédoublonnage chez Meta)
        $this->get($this->storeUrl($this->store, $redirect))->assertOk()->assertSee("jmTrack('purchase'", false)->assertSee('"order-'.$order->id.'"', false);
    }
}
