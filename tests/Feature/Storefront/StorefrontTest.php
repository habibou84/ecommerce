<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Collection;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Page;
use App\Models\Place;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use App\Services\Storefront\CartService;
use App\Services\Storefront\ThemeSettings;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode', 'whatsapp_phone' => '+2250700000001']);
        $this->store->forceFill(['storefront_enabled' => true])->save();
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, [
            'title' => 'Robe wax',
            'description' => "Coupe évasée.\nTissu 100 % coton.",
            'options' => [['name' => 'Taille', 'values' => ['S', 'M']], ['name' => 'Couleur', 'values' => ['Rouge', 'Bleu']]],
            'variants' => [
                ['options' => ['S', 'Rouge'], 'price' => 15000, 'on_hand' => 3],
                ['options' => ['S', 'Bleu'], 'price' => 15000, 'on_hand' => 0],
                ['options' => ['M', 'Rouge'], 'price' => 16000, 'on_hand' => 1],
            ],
        ]);
    }

    private function ajax(string $method, string $path, array $data = [], ?string $cart = null): TestResponse
    {
        $request = $this->withCredentials()->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);
        if ($cart) {
            $request = $request->withCookie(CartService::COOKIE, $cart);
        }

        return $request->json($method, $this->storeUrl($this->store, $path), $data);
    }

    private function addToCart(int $variantId, int $quantity, ?string $cart = null): TestResponse
    {
        return $this->ajax('POST', '/panier/articles', ['variant_id' => $variantId, 'quantity' => $quantity], $cart);
    }

    private function place(string $name): Place
    {
        return Place::whereNull('store_id')->where('name', $name)->firstOrFail();
    }

    public function test_a_closed_storefront_shows_the_waiting_page_and_hides_from_search_engines(): void
    {
        $this->store->forceFill(['storefront_enabled' => false])->save();

        $this->get($this->storeUrl($this->store))->assertOk()->assertSee('ouvre bientôt');
        $this->get($this->storeUrl($this->store, '/produits/robe-wax'))->assertOk()->assertSee('ouvre bientôt')->assertDontSee('15 000 F');
        $this->get($this->storeUrl($this->store, '/robots.txt'))->assertOk()->assertSee('Disallow: /');
        $this->ajax('POST', '/panier/articles', ['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1])->assertNotFound();
    }

    public function test_preview_link_opens_a_closed_storefront_for_one_hour(): void
    {
        $this->store->forceFill(['storefront_enabled' => false])->save();
        $link = URL::temporarySignedRoute('storefront.preview', now()->addHour(), ['boutique' => $this->store->id], false);

        $response = $this->get($this->storeUrl($this->store, $link))->assertRedirect('/');
        $cookie = $response->getCookie('apercu');
        $this->assertNotNull($cookie);

        $this->withCookie('apercu', $cookie->getValue())->get($this->storeUrl($this->store, '/produits/robe-wax'))->assertOk()->assertSee('Robe wax');

        // Lien d'une autre boutique ou falsifié : refusé
        $other = $this->createStore(['slug' => 'autre']);
        $this->get($this->storeUrl($other, $link))->assertForbidden();
        $this->get($this->storeUrl($this->store, '/apercu?boutique='.$this->store->id))->assertForbidden();
    }

    public function test_home_listing_and_search(): void
    {
        $sac = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 8000, 'on_hand' => 2]]]);
        app(CatalogWriter::class)->save($this->store, null, ['title' => 'Brouillon', 'status' => 'draft', 'variants' => [['price' => 1000]]]);
        app(CatalogWriter::class)->save($this->store, null, ['title' => 'Caché', 'is_visible_online' => false, 'variants' => [['price' => 1000]]]);

        $this->get($this->storeUrl($this->store))->assertOk()
            ->assertSee('Awa Mode')->assertSee('Nouveautés')->assertSee('Robe wax')->assertSee('Sac en pagne')
            ->assertDontSee('Brouillon')->assertDontSee('Caché')
            ->assertSee('"@type":"Store"', false)
            ->assertSee('<link rel="canonical" href="http://awa.localhost/">', false);

        $this->get($this->storeUrl($this->store, '/produits?q=sac'))->assertOk()->assertSee('Sac en pagne')->assertDontSee('Robe wax');
        $this->get($this->storeUrl($this->store, '/produits?tri=prix-croissant'))->assertOk()->assertSeeInOrder(['Sac en pagne', 'Robe wax']);
        $this->get($this->storeUrl($this->store, '/produits?tri=nimporte'))->assertStatus(302);

        $collection = Collection::create(['store_id' => $this->store->id, 'title' => 'Accessoires', 'slug' => 'accessoires', 'is_visible_online' => true]);
        $collection->products()->attach($sac->id, ['position' => 1]);
        $this->get($this->storeUrl($this->store, '/collections/accessoires'))->assertOk()->assertSee('Sac en pagne')->assertDontSee('Robe wax');
        $this->get($this->storeUrl($this->store, '/collections/inconnue'))->assertNotFound();
    }

    public function test_product_page_lists_variants_with_stock_and_seo(): void
    {
        $response = $this->get($this->storeUrl($this->store, '/produits/robe-wax'))->assertOk()
            ->assertSee('<title>Robe wax · Awa Mode</title>', false)
            ->assertSee('<meta property="og:type" content="product">', false)
            ->assertSee('"@type":"Product"', false)
            ->assertSee('Commander sur WhatsApp')
            ->assertSee('Tissu 100 % coton.')
            ->assertSee('Plus que 3 en stock');

        preg_match('#<script type="application/json" id="product-data">(.*?)</script>#s', $response->getContent(), $m);
        $data = json_decode($m[1], true);
        $this->assertSame(2, $data['optionCount']);
        $this->assertSame([['S', 'Rouge', 3], ['S', 'Bleu', 0], ['M', 'Rouge', 1]], array_map(fn ($v) => [...$v['options'], $v['available']], $data['variants']));
        $this->assertSame($this->robe->variants[0]->id, $data['selected']);

        $this->get($this->storeUrl($this->store, '/produits/inconnu'))->assertNotFound();
    }

    public function test_cart_follows_the_visitor_cookie_and_respects_stock(): void
    {
        [$sRouge, $sBleu, $mRouge] = $this->robe->variants->all();
        $other = $this->createStore(['slug' => 'autre']);
        $foreign = app(CatalogWriter::class)->save($other, null, ['title' => 'Autre', 'variants' => [['price' => 1000]]]);

        $response = $this->addToCart($sRouge->id, 2)->assertOk()->assertJsonPath('data.count', 2)->assertJsonPath('data.subtotal', 30000);
        $token = $response->getCookie(CartService::COOKIE)->getValue();
        $this->assertSame(1, Cart::count());

        $this->addToCart($sRouge->id, 2, $token)->assertStatus(422)->assertJsonPath('errors.quantity.0', "Il n'en reste que 3.");
        $this->addToCart($sBleu->id, 1, $token)->assertStatus(422)->assertJsonPath('errors.quantity.0', 'Cet article est épuisé.');
        $this->addToCart($mRouge->id, 1, $token)->assertOk()->assertJsonPath('data.count', 3)->assertJsonPath('data.subtotal', 46000);

        // Quantité fixée, puis retrait
        $this->ajax('POST', '/panier/articles', ['variant_id' => $sRouge->id, 'quantity' => 1, 'replace' => true], $token)->assertJsonPath('data.count', 2);
        $this->ajax('POST', '/panier/articles', ['variant_id' => $mRouge->id, 'quantity' => 0, 'replace' => true], $token)
            ->assertJsonPath('data.count', 1)->assertJsonCount(1, 'data.lines');

        $this->withCookie(CartService::COOKIE, $token)->get($this->storeUrl($this->store, '/panier'))->assertOk()
            ->assertSee('Robe wax')->assertSee('S / Rouge')->assertSee('15 000 F')->assertSee('<meta name="robots" content="noindex">', false);

        // Sans l'en-tête des appels de nos pages : refusé (pas de jeton CSRF sur la boutique)
        $this->withoutHeader('X-Requested-With')->postJson($this->storeUrl($this->store, '/panier/articles'), ['variant_id' => $sRouge->id, 'quantity' => 1])->assertStatus(419);

        // Article d'une autre boutique : refusé
        $this->addToCart($foreign->variants[0]->id, 1, $token)->assertStatus(422);
    }

    public function test_checkout_quotes_fees_and_places_a_cash_on_delivery_order(): void
    {
        [$sRouge] = $this->robe->variants->all();
        $token = $this->addToCart($sRouge->id, 2)->getCookie(CartService::COOKIE)->getValue();

        $this->get($this->storeUrl($this->store, '/commande'))->assertRedirect('/panier');
        $this->withCookie(CartService::COOKIE, $token)->get($this->storeUrl($this->store, '/commande'))->assertOk()->assertSee('Finaliser ma commande');

        $angre = $this->place('Angré');
        $this->ajax('GET', '/lieux?q=angre', [], $token)->assertOk()->assertJsonPath('data.0.id', $angre->id);
        $this->ajax('POST', '/commande/devis', ['delivery_method' => 'delivery', 'place_id' => $angre->id], $token)
            ->assertOk()->assertJsonPath('data.fee', 1500)->assertJsonPath('data.total', 31500)->assertJsonPath('data.zone', 'Abidjan');

        $this->ajax('POST', '/commande/contact', ['name' => 'Fatou', 'phone' => '05 06 07 08 09'], $token)->assertOk();
        $this->assertSame('+2250506070809', Cart::first()->phone);

        $this->ajax('POST', '/commande', ['name' => 'Fatou Diallo', 'phone' => '0506', 'delivery_method' => 'delivery'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->ajax('POST', '/commande', ['name' => 'Fatou Diallo', 'phone' => '05 06 07 08 09', 'delivery_method' => 'delivery'], $token)
            ->assertStatus(422)->assertJsonPath('errors.place_id.0', 'Indiquez votre quartier.');

        $response = $this->ajax('POST', '/commande', [
            'name' => 'Fatou Diallo', 'phone' => '05 06 07 08 09', 'delivery_method' => 'delivery',
            'place_id' => $angre->id, 'address' => 'Cité Arcades', 'landmark' => 'Face pharmacie', 'note' => 'Après 17 h',
        ], $token)->assertCreated();

        $order = Order::with('items')->sole();
        $this->assertSame('/commande/merci/'.$order->tracking_token, $response->json('redirect'));
        $this->assertSame('online_store', $order->source->value);
        $this->assertSame(OrderStatus::PendingConfirmation, $order->status);
        $this->assertSame([30000, 1500, 31500], [$order->subtotal, $order->shipping_fee, $order->total]);
        $this->assertSame(['Fatou Diallo', '+2250506070809', 'Après 17 h'], [$order->customer_name, $order->customer_phone, $order->customer_note]);
        $this->assertSame(2, StockLevel::where('variant_id', $sRouge->id)->value('reserved'));

        $cart = Cart::first();
        $this->assertSame(['converted', $order->id], [$cart->status, $cart->order_id]);

        $this->get($this->storeUrl($this->store, $response->json('redirect')))->assertOk()
            ->assertSee('Merci Fatou')->assertSee('#1001')->assertSee('31 500 F')->assertSee('/suivi/'.$order->tracking_token);

        // Le panier converti n'est plus utilisé : un nouvel ajout repart d'un panier vide
        $this->addToCart($sRouge->id, 1, $token)->assertJsonPath('data.count', 1);
    }

    public function test_checkout_outside_the_zones_and_honeypot(): void
    {
        // La zone ne couvre plus que Cocody
        DeliveryZone::forStore($this->store->id)->sole()->places()->sync([$this->place('Cocody')->id => ['store_id' => $this->store->id]]);
        [$sRouge] = $this->robe->variants->all();
        $token = $this->addToCart($sRouge->id, 1)->getCookie(CartService::COOKIE)->getValue();
        $yopougon = $this->place('Yopougon');

        $this->ajax('POST', '/commande/devis', ['delivery_method' => 'delivery', 'place_id' => $yopougon->id], $token)
            ->assertOk()->assertJsonPath('data.fee', null)->assertJsonPath('data.total', null);

        $data = ['name' => 'Koffi', 'phone' => '0102030405', 'delivery_method' => 'delivery', 'place_id' => $yopougon->id];
        $this->ajax('POST', '/commande', $data + ['website' => 'http://spam'], $token)->assertStatus(422);
        $this->assertSame(0, Order::count());

        $this->ajax('POST', '/commande', $data, $token)->assertCreated();
        $this->assertStringContainsString('frais à confirmer', Order::sole()->internal_note);
    }

    public function test_theme_settings_change_the_storefront(): void
    {
        $themes = app(ThemeSettings::class);
        $themes->update($this->store, ['primary_color' => '#1D4ED8', 'announcement' => 'Livraison offerte dès 30 000 F', 'hero_title' => 'La mode wax']);

        $this->get($this->storeUrl($this->store))->assertOk()
            ->assertSee('--brand: #1d4ed8', false)->assertSee('Livraison offerte dès 30 000 F')->assertSee('La mode wax');

        $themes->activate($this->store->refresh(), 'marche');
        $this->get($this->storeUrl($this->store))->assertOk()
            ->assertSee('cards-rounded')->assertSee('--brand: #c2410c', false)->assertSee('La mode wax');
    }

    public function test_pages_menus_sitemap_and_robots(): void
    {
        Page::create(['store_id' => $this->store->id, 'title' => 'Livraison et retours', 'slug' => 'livraison', 'body' => "## Délais\n\nSous **24 h** à Abidjan.<script>alert(1)</script>"]);
        Page::create(['store_id' => $this->store->id, 'title' => 'Brouillon', 'slug' => 'brouillon', 'is_visible' => false]);

        $this->get($this->storeUrl($this->store, '/pages/livraison'))->assertOk()
            ->assertSee('<h2>Délais</h2>', false)->assertSee('<strong>24 h</strong>', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get($this->storeUrl($this->store, '/pages/brouillon'))->assertNotFound();

        // Menu par défaut : pages visibles en pied de page
        $this->get($this->storeUrl($this->store))->assertSee('href="/pages/livraison"', false)->assertDontSee('/pages/brouillon');

        $this->get($this->storeUrl($this->store, '/sitemap.xml'))->assertOk()->assertHeader('Content-Type', 'application/xml')
            ->assertSee('<loc>http://awa.localhost/produits/robe-wax</loc>', false)
            ->assertSee('<loc>http://awa.localhost/pages/livraison</loc>', false)
            ->assertDontSee('brouillon');

        $this->get($this->storeUrl($this->store, '/robots.txt'))->assertOk()->assertSee('Sitemap: http://awa.localhost/sitemap.xml');
        $this->get($this->platformUrl('/robots.txt'))->assertOk()->assertSee('Disallow: /connexion');
    }

    public function test_utm_of_the_landing_page_is_kept_with_the_cart(): void
    {
        $response = $this->get($this->storeUrl($this->store, '/produits/robe-wax?utm_source=tiktok&utm_campaign=rentree'));
        $origin = $response->getCookie(CartService::ORIGIN_COOKIE)->getValue();

        $this->withCookie(CartService::ORIGIN_COOKIE, $origin)->withCredentials()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson($this->storeUrl($this->store, '/panier/articles'), ['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1])->assertOk();

        $this->assertSame(['utm_source' => 'tiktok', 'utm_campaign' => 'rentree'], Cart::first()->utm);
    }
}
