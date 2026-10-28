<?php

namespace Tests\Feature\Marketing;

use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Marketing\CatalogFeed;
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
        // Jeton dans le corps, jamais dans l'adresse (qui peut finir dans les journaux)
        $this->assertStringEndsWith('/123456789012345/events', $sent[0]->url());
        $this->assertSame('EAAB-secret', $sent[0]['access_token']);
        $event = $sent[0]['data'][0];
        $this->assertSame(['Purchase', 'order-'.$order->id, $order->total], [$event['event_name'], $event['event_id'], $event['custom_data']['value']]);
        $this->assertSame([hash('sha256', '2250506070809')], $event['user_data']['ph']);
        $this->assertSame([hash('sha256', 'fatou'), hash('sha256', 'koné')], [$event['user_data']['fn'][0], $event['user_data']['ln'][0]]);
        $this->assertSame(['fb.1.1700000000000.42', 'Mozilla/5.0 Test'], [$event['user_data']['fbp'], $event['user_data']['client_user_agent']]);
        $this->assertStringEndsWith('.IwAR123', $event['user_data']['fbc']);
        $this->assertNotNull($order->fresh()->conversion_sent_at);
        // Article désigné comme dans le catalogue (sans référence : V + numéro de la variante)
        $item = 'V'.$this->robe->variants[0]->id;
        $this->assertSame([$item], $event['custom_data']['content_ids']);
        $this->assertSame([['id' => $item, 'quantity' => 1, 'item_price' => 15000]], $event['custom_data']['contents']);

        // Le pixel envoie l'achat avec le même identifiant (dédoublonnage chez Meta)
        $this->get($this->storeUrl($this->store, $redirect))->assertOk()->assertSee("jmTrack('purchase'", false)->assertSee('"order-'.$order->id.'"', false)
            ->assertSee('"id":"'.$item.'"', false);
    }

    public function test_the_pixel_names_items_like_the_catalog(): void
    {
        $this->store->forceFill(['marketing' => ['meta_pixel_id' => '123456789012345']])->save();
        $sac = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac', 'variants' => [['price' => 8000, 'on_hand' => 3, 'sku' => 'SAC-NOIR']]]);
        // Même règle que les lignes du catalogue : la référence, sinon V + numéro de la variante
        $this->assertSame(['SAC-NOIR', 'V12', null], [CatalogFeed::itemId('SAC-NOIR', 3), CatalogFeed::itemId('', 12), CatalogFeed::itemId(null, null)]);

        // Produit vu : l'identifiant de la variante affichée, celui du catalogue
        $this->get($this->storeUrl($this->store, '/produits/'.$sac->slug))->assertOk()
            ->assertSee("jmTrack('view_item'", false)->assertSee('"id":"SAC-NOIR"', false)->assertSee('"catalog_id":"SAC-NOIR"', false);

        // Début de commande : idem pour chaque ligne du panier
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $cart = $this->withCredentials()->withHeaders($headers)
            ->postJson($this->storeUrl($this->store, '/panier/articles'), ['variant_id' => $sac->variants[0]->id, 'quantity' => 2])
            ->getCookie(CartService::COOKIE)->getValue();
        $this->withCredentials()->withCookie(CartService::COOKIE, $cart)->get($this->storeUrl($this->store, '/commande'))->assertOk()
            ->assertSee("jmTrack('begin_checkout'", false)->assertSee('"id":"SAC-NOIR"', false);
    }
}
