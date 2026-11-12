<?php

namespace Tests\Feature\Marketing;

use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Marketing\MetaAds;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Platform\Features;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Menu Boost : connexion Facebook et pixel vérifié, meilleures ventes à booster avec un lien suivi et un texte prêt,
 * commandes venues des boosts.
 */
class BoostTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private Product $sac;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.app_id' => '7777', 'services.meta.app_secret' => 'platform-secret']);
        Http::preventStrayRequests();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 20]]]);
        $this->sac = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 8000, 'on_hand' => 20]]]);
        $this->actingAs($this->store->owner);
    }

    private function order(Product $product, int $quantity, ?array $utm = null): Order
    {
        $order = app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
            'items' => [['variant_id' => $product->variants()->value('id'), 'quantity' => $quantity]],
        ], $this->store->owner);
        if ($utm) {
            $order->forceFill(['utm' => $utm])->save();
        }

        return $order;
    }

    public function test_connection_pixel_check_products_to_boost_and_orders_from_boosts(): void
    {
        // Pas encore relié : la page le dit, sans vérification du pixel
        $this->storeApi($this->store, 'GET', 'marketing/boost')->assertOk()
            ->assertJsonPath('data.connection.connected', false)->assertJsonPath('data.pixel', null);

        // Relié : compte, pixel et vérification des événements reçus par Meta
        $this->store->forceFill(['marketing' => ['meta_pixel_id' => '222222', 'meta_catalog_id' => '333333']])->save();
        SocialAccount::create(['store_id' => $this->store->id, 'network' => MetaAds::NETWORK, 'external_id' => '111', 'name' => 'Awa SARL', 'access_token' => 'long-token', 'status' => 'active']);
        Http::fake(['graph.facebook.com/*/222222/stats*' => Http::response(['data' => [
            ['start_time' => '2026-11-10T00:00:00+0000', 'data' => [['value' => 'PageView', 'count' => 40], ['value' => 'Purchase', 'count' => 2]]],
            ['start_time' => '2026-11-11T00:00:00+0000', 'data' => [['value' => 'PageView', 'count' => 10], ['value' => 'ViewContent', 'count' => 7]]],
        ]])]);

        // Le sac se vend le mieux ; une commande de la robe est venue d'un boost
        $this->order($this->sac, 3);
        $this->order($this->robe, 1, ['utm_source' => 'facebook', 'utm_medium' => 'boost', 'utm_campaign' => 'boost-'.$this->robe->id]);
        $this->order($this->robe, 1, ['utm_source' => 'facebook', 'utm_campaign' => 'post-12']);

        $data = $this->storeApi($this->store, 'GET', 'marketing/boost')->assertOk()
            ->assertJsonPath('data.connection.connected', true)->assertJsonPath('data.connection.business', 'Awa SARL')
            ->assertJsonPath('data.connection.pixel_id', '222222')->assertJsonPath('data.connection.conversions_ready', true)
            ->json('data');
        $this->assertSame(['PageView' => 50, 'ViewContent' => 7, 'Purchase' => 2], collect($data['pixel'])->pluck('count', 'event')->filter()->all());

        // Produits à booster : meilleures ventes d'abord, lien suivi et texte prêt
        $this->assertSame(['Sac en pagne', 'Robe wax'], array_column($data['products'], 'title'));
        $this->assertSame(3, $data['products'][0]['sold']);
        $this->assertStringContainsString('/produits/'.$this->sac->slug.'?utm_source=facebook&utm_medium=boost&utm_campaign=boost-'.$this->sac->id, $data['products'][0]['link']);
        $this->assertStringContainsString($data['products'][0]['link'], $data['products'][0]['text']);

        // Commandes venues des boosts : seulement les liens « boost- »
        $this->assertSame(1, $data['results']['orders']);
        $this->assertSame([['id' => $this->robe->id, 'title' => 'Robe wax', 'orders' => 1]], array_map(fn ($p) => array_intersect_key($p, array_flip(['id', 'title', 'orders'])), $data['results']['products']));
        // Vérification du pixel relue au plus une fois par heure
        $this->storeApi($this->store, 'GET', 'marketing/boost')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_the_menu_can_be_turned_off_from_the_console(): void
    {
        app(Features::class)->set('boost', 'hidden');
        $this->storeApi($this->store, 'GET', 'marketing/boost')->assertForbidden()->assertJsonPath('code', 'feature_off');
        $this->storeApi($this->store, 'GET', 'auth/me')->assertJsonPath('data.features.boost', false);
    }
}
