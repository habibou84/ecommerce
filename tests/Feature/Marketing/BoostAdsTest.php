<?php

namespace Tests\Feature\Marketing;

use App\Models\Boost;
use App\Models\Collection;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Live\LiveBilling;
use App\Services\Marketing\BoostAds;
use App\Services\Marketing\MetaAds;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Platform\Features;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\TestCase;

/**
 * Assistant Boost : la pub créée chez Meta depuis l'application (un produit, un carrousel, le catalogue), suivie,
 * mise en pause ou prolongée ; commandes venues de la pub comptées et prix de l'assistant retiré du crédit.
 */
class BoostAdsTest extends TestCase
{
    use LiveProductsHavePhotos;

    private Store $store;

    private Product $robe;

    private Product $sac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giveProductsPhotos();
        config(['services.meta.app_id' => '7777', 'services.meta.app_secret' => 'platform-secret']);
        Http::preventStrayRequests();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 20]]]);
        $this->sac = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 8000, 'on_hand' => 20]]]);
        $this->store->forceFill(['marketing' => ['meta_pixel_id' => '222222', 'meta_catalog_id' => '333333', 'meta_business_id' => '111']])->save();
        SocialAccount::create(['store_id' => $this->store->id, 'network' => MetaAds::NETWORK, 'external_id' => '111', 'name' => 'Awa SARL', 'access_token' => 'long-token', 'status' => 'active']);
        $this->actingAs($this->store->owner);
    }

    private function fakeMeta(array $extra = []): void
    {
        Http::fake($extra + [
            'graph.facebook.com/*/111/owned_ad_accounts*' => Http::response(['data' => [
                ['id' => 'act_9', 'name' => 'Vieux compte', 'account_status' => 2, 'currency' => 'XOF'],
                ['id' => 'act_5', 'name' => 'Awa Pub', 'account_status' => 1, 'currency' => 'EUR', 'funding_source' => '777'],
            ]]),
            'graph.facebook.com/*/111/client_ad_accounts*' => Http::response(['data' => []]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => []]),
            'graph.facebook.com/*/111/owned_pages*' => Http::response(['data' => [['id' => '444', 'name' => 'Awa Mode']]]),
            'graph.facebook.com/*/111/client_pages*' => Http::response(['data' => []]),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_5/delivery_estimate*' => Http::response(['data' => [['estimate_mau_lower_bound' => 120000, 'estimate_mau_upper_bound' => 150000]]]),
            'graph.facebook.com/*/333333/product_sets' => Http::response(['id' => 'set1']),
            'graph.facebook.com/*/act_5/campaigns' => Http::response(['id' => 'camp1']),
            'graph.facebook.com/*/act_5/adsets' => Http::response(['id' => 'adset1']),
            'graph.facebook.com/*/act_5/adcreatives' => Http::response(['id' => 'crea1']),
            'graph.facebook.com/*/act_5/ads' => Http::response(['id' => 'ad1']),
            'graph.facebook.com/*/camp1/insights*' => Http::response(['data' => [['spend' => '12.50', 'clicks' => '312', 'impressions' => '9000']]]),
            'graph.facebook.com/*/ad1?*' => Http::response(['effective_status' => 'ACTIVE']),
            'graph.facebook.com/*/camp1' => Http::response(['success' => true]),
            'graph.facebook.com/*/adset1' => Http::response(['success' => true]),
        ]);
    }

    private function launch(array $data = []): TestResponse
    {
        return $this->storeApi($this->store, 'POST', 'marketing/boosts', $data + [
            'kind' => 'product', 'product_ids' => [$this->robe->id], 'daily_budget' => 2500, 'days' => 7, 'zones' => ['cocody', 'yopougon'],
            'age_min' => 18, 'age_max' => 45, 'gender' => 'women', 'message' => 'La robe wax est disponible !', 'ad_account_id' => 'act_5', 'page_id' => '444',
        ]);
    }

    private function sent(string $path): Request
    {
        $found = null;
        Http::assertSent(function (Request $r) use ($path, &$found) {
            if ($r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $path)) {
                $found = $r;

                return true;
            }

            return false;
        });

        return $found;
    }

    public function test_one_product_boost_is_created_at_meta_and_followed_with_its_orders(): void
    {
        $this->fakeMeta();
        app(LiveBilling::class)->update(['boost_paid' => true, 'boost_price' => 300]);
        $this->store->forceFill(['live_credit' => 5000])->save();

        // Ce qu'il faut : compte utilisable (actif, devise prise en charge) d'abord, page, public estimé
        $setup = $this->storeApi($this->store, 'GET', 'marketing/boost/setup')->assertOk()->json('data');
        $this->assertSame(['act_5', 'act_9'], array_column($setup['ad_accounts'], 'id'));
        $this->assertTrue($setup['ad_accounts'][0]['usable'] && $setup['ad_accounts'][0]['card']);
        $this->assertSame('444', $setup['pages'][0]['id']);
        $this->assertSame(['paid' => true, 'price' => 300, 'credit' => 5000], $setup['fee']);
        $this->storeApi($this->store, 'POST', 'marketing/boost/audience', ['ad_account_id' => 'act_5', 'zones' => ['cocody'], 'age_min' => 18, 'age_max' => 45, 'gender' => 'all'])
            ->assertOk()->assertJsonPath('data', ['lower' => 120000, 'upper' => 150000]);

        $boost = $this->launch()->assertCreated()->assertJsonPath('data.status', 'review')->assertJsonPath('data.budget_total', 17500)->json('data');

        // Campagne ventes (pixel relié), budget converti en centimes d'euro, cercles autour des communes, femmes
        $this->assertSame('OUTCOME_SALES', $this->sent('/act_5/campaigns')['objective']);
        $adset = $this->sent('/act_5/adsets');
        $this->assertSame(381, $adset['daily_budget']);
        $this->assertSame(['pixel_id' => '222222', 'custom_event_type' => 'PURCHASE'], $adset['promoted_object']);
        $this->assertCount(2, $adset['targeting']['geo_locations']['custom_locations']);
        $this->assertSame([2], $adset['targeting']['genders']);
        // Photo du produit, lien suivi vers sa page avec la marque du boost
        $link = $this->sent('/act_5/adcreatives')['object_story_spec']['link_data'];
        $this->assertSame('444', $this->sent('/act_5/adcreatives')['object_story_spec']['page_id']);
        $this->assertStringContainsString('/produits/'.$this->robe->slug.'?', $link['link']);
        $this->assertStringContainsString('utm_content=b'.$boost['id'], $link['link']);
        $this->assertSame('ACTIVE', $this->sent('/camp1')['status']);

        // Une commande venue de la pub (et une autre sans) : comptée, prix de l'assistant retiré du crédit
        $this->order(['utm_source' => 'facebook', 'utm_content' => 'b'.$boost['id']]);
        $this->order(['utm_source' => 'facebook']);
        $this->artisan('boosts:sync')->assertSuccessful();
        $row = $this->storeApi($this->store, 'GET', 'marketing/boosts')->assertOk()->json('data.0');
        $this->assertSame(['active', 8199, 312, 1, 300], [$row['status'], $row['spend'], $row['clicks'], $row['orders'], $row['fee']]);
        $this->assertSame(4700, $this->store->fresh()->live_credit);
        // Relu sans nouvelle commande : rien de plus retiré
        app(BoostAds::class)->sync(Boost::find($boost['id']));
        $this->assertSame(4700, $this->store->fresh()->live_credit);

        // Pause, prolongation de 3 jours, arrêt
        $this->storeApi($this->store, 'POST', "marketing/boosts/{$boost['id']}/action", ['action' => 'pause'])->assertOk();
        $this->assertSame('PAUSED', $this->sent('/camp1')['status']);
        $this->storeApi($this->store, 'POST', "marketing/boosts/{$boost['id']}/action", ['action' => 'extend', 'days' => 3])->assertOk()->assertJsonPath('data.days', 10);
        $this->sent('/adset1');
        $this->storeApi($this->store, 'POST', "marketing/boosts/{$boost['id']}/action", ['action' => 'stop'])->assertOk();
        $this->assertSame('ended', Boost::find($boost['id'])->status);
    }

    public function test_carousel_and_catalog_boosts(): void
    {
        $this->fakeMeta();
        // Carrousel : une carte par produit, chacune vers sa page
        $this->launch(['kind' => 'products', 'product_ids' => [$this->sac->id]])->assertStatus(422);
        $this->launch(['kind' => 'products', 'product_ids' => [$this->sac->id, $this->robe->id], 'zones' => [], 'gender' => 'all'])->assertCreated();
        $creative = $this->sent('/act_5/adcreatives');
        $cards = $creative['object_story_spec']['link_data']['child_attachments'];
        $this->assertSame(['Sac en pagne', 'Robe wax'], array_column($cards, 'name'));
        $this->assertStringContainsString('utm_campaign=boost-'.$this->sac->id, $cards[0]['link']);
        $this->assertSame(['countries' => ['CI']], $this->sent('/act_5/adsets')['targeting']['geo_locations']);

        // Catalogue d'une collection : ensemble de produits de la collection, pub dynamique
        $collection = Collection::create(['store_id' => $this->store->id, 'title' => 'Robes', 'slug' => 'robes']);
        $collection->products()->attach($this->robe->id, ['position' => 1]);
        $this->launch(['kind' => 'catalog', 'collection_id' => $collection->id, 'product_ids' => null])->assertCreated()->assertJsonPath('data.name', 'Catalogue · Robes');
        $filter = json_decode($this->sent('/333333/product_sets')['filter'], true);
        $this->assertCount(1, $filter['and'][1]['retailer_id']['is_any']);
        $this->assertSame(['product_catalog_id' => '333333'], $this->sent('/act_5/campaigns')['promoted_object']);
        $this->assertSame('set1', $this->sent('/act_5/adsets')['promoted_object']['product_set_id']);
        $this->assertSame('{{product.name}}', $this->sent('/act_5/adcreatives')['object_story_spec']['template_data']['name']);
    }

    public function test_meta_refusal_is_reported_and_the_campaign_removed(): void
    {
        $this->fakeMeta(['graph.facebook.com/*/act_5/adcreatives' => Http::response(['error' => ['message' => 'Page non autorisée']], 400)]);
        $this->launch()->assertStatus(422)->assertJsonPath('message', 'Meta n\'a pas créé la pub : Meta : Page non autorisée');
        $this->assertSame(['failed', 'Meta : Page non autorisée'], [Boost::sole()->status, Boost::sole()->error]);
        $this->assertSame('DELETED', $this->sent('/camp1')['status']);

        // Compte publicitaire inactif ou crédit trop bas : refusé avant Meta
        $this->launch(['ad_account_id' => 'act_9'])->assertStatus(422);
        app(LiveBilling::class)->update(['boost_paid' => true, 'boost_price' => 300]);
        $this->launch()->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'crédit'));
    }

    public function test_the_assistant_can_be_turned_off_from_the_console(): void
    {
        app(Features::class)->set('boost_ads', 'hidden');
        $this->storeApi($this->store, 'GET', 'marketing/boost/setup')->assertForbidden();
    }

    private function order(array $utm): Order
    {
        $order = app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
            'items' => [['variant_id' => $this->robe->variants()->value('id'), 'quantity' => 1]],
        ], $this->store->owner);
        $order->forceFill(['utm' => $utm])->save();

        return $order;
    }
}
