<?php

namespace Tests\Feature\Marketing;

use App\Models\SocialAccount;
use App\Models\Store;
use App\Services\Marketing\CatalogFeed;
use App\Services\Marketing\MetaAds;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pixel et catalogue Meta branchés en un clic : connexion Facebook, choix du compte Business, du pixel et du
 * catalogue, lien du catalogue ajouté chez Meta, pixel relié, accès gardé pour l'API Conversions.
 */
class MetaAdsTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.app_id' => '7777', 'services.meta.app_secret' => 'platform-secret']);
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->actingAs($this->store->owner);
    }

    private function fakeMeta(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::sequence()->push(['access_token' => 'short'])->push(['access_token' => 'long-token']),
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['expires_at' => now()->addDays(60)->timestamp]]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['data' => [['id' => '111', 'name' => 'Awa SARL']]]),
            'graph.facebook.com/*/111/owned_pixels*' => Http::response(['data' => [['id' => '222222', 'name' => 'Pixel Awa']]]),
            'graph.facebook.com/*/111/client_pixels*' => Http::response(['error' => ['message' => 'Permission']], 403),
            'graph.facebook.com/*/111/owned_product_catalogs' => Http::response(['id' => '333333']),
            'graph.facebook.com/*/111/owned_product_catalogs*' => Http::response(['data' => []]),
            'graph.facebook.com/*/111/client_product_catalogs*' => Http::response(['data' => []]),
            'graph.facebook.com/*/333333/product_feeds' => Http::response(['id' => '444']),
            'graph.facebook.com/*/333333/product_feeds*' => Http::response(['data' => []]),
            'graph.facebook.com/*/333333/external_event_sources' => Http::response(['error' => ['message' => 'Pixel non autorisé']], 400),
        ]);
    }

    public function test_pixel_and_catalog_are_plugged_in_with_one_facebook_login(): void
    {
        $this->fakeMeta();
        $url = $this->storeApi($this->store, 'POST', 'marketing/tracking/meta/connect')->assertOk()->json('url');
        $this->assertStringContainsString('catalog_management', urldecode($url));
        $this->assertStringContainsString('redirect_uri='.rawurlencode('http://localhost/reseaux/meta/retour'), $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // Retour de Facebook (même adresse que la page) : on revient sur le suivi de la boutique
        $back = $this->get('http://localhost/reseaux/meta/retour?code=abc&state='.rawurlencode($query['state']))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith($this->storeUrl($this->store, '/gestion/marketing/suivi?meta='), $back);
        $key = explode('meta=', $back)[1];

        $this->storeApi($this->store, 'GET', "marketing/tracking/meta/{$key}")->assertOk()
            ->assertJsonPath('data.businesses.0.name', 'Awa SARL')->assertJsonPath('data.assets', null)->assertJsonMissingPath('data.access');
        $this->storeApi($this->store, 'GET', "marketing/tracking/meta/{$key}?business=111")->assertOk()
            ->assertJsonPath('data.assets.pixels.0.id', '222222')->assertJsonPath('data.assets.catalogs', []);

        // Un pixel d'un autre compte Business est refusé
        $this->storeApi($this->store, 'POST', "marketing/tracking/meta/{$key}", ['business_id' => '111', 'pixel_id' => '999999', 'catalog_id' => 'new'])
            ->assertStatus(422)->assertJsonValidationErrors('pixel_id');

        $res = $this->storeApi($this->store, 'POST', "marketing/tracking/meta/{$key}", ['business_id' => '111', 'pixel_id' => '222222', 'catalog_id' => 'new'])->assertOk()
            ->assertJsonPath('data.meta_pixel_id', '222222')->assertJsonPath('data.meta_connection.business', 'Awa SARL')
            ->assertJsonPath('data.meta_connection.catalog_id', '333333')->assertJsonPath('data.conversions_ready', true);
        // Pixel pas relié : dit simplement, le reste est branché
        $this->assertCount(1, $res->json('warnings'));
        $this->assertStringContainsString('Sources d\'événements', $res->json('warnings.0'));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/333333/product_feeds')
            && $r['name'] === 'Jibiat Marchand' && $r['schedule'] === ['interval' => 'HOURLY', 'url' => app(CatalogFeed::class)->url($this->store->fresh())]);
        $account = SocialAccount::where('network', MetaAds::NETWORK)->sole();
        $this->assertSame(['111', 'long-token'], [$account->external_id, $account->access_token]);
        $this->assertSame('long-token', app(MetaAds::class)->conversionsToken($this->store->fresh()));

        // Connexion utilisée une seule fois
        $this->storeApi($this->store, 'GET', "marketing/tracking/meta/{$key}")->assertStatus(422);

        // Enregistrer les autres pixels garde le catalogue ; un jeton collé à la main passe avant la connexion
        $this->storeApi($this->store, 'PUT', 'marketing/tracking', ['meta_pixel_id' => '222222', 'ga4_id' => 'G-ABC123XYZ', 'meta_capi_token' => 'EAAB-main'])
            ->assertJsonPath('data.meta_connection.catalog_id', '333333');
        $this->assertSame('EAAB-main', app(MetaAds::class)->conversionsToken($this->store->fresh()));
        $this->storeApi($this->store, 'PUT', 'marketing/tracking', ['meta_pixel_id' => '222222', 'remove_capi_token' => true]);

        // Accès expiré : plus d'envoi par le serveur, l'écran demande de reconnecter
        $account->forceFill(['token_expires_at' => now()->subDay()])->save();
        $this->storeApi($this->store, 'GET', 'marketing/tracking')->assertJsonPath('data.conversions_ready', false)->assertJsonPath('data.meta_connection.expired', true);

        // Déconnexion : le pixel reste
        $this->storeApi($this->store, 'DELETE', 'marketing/tracking/meta')->assertOk()
            ->assertJsonPath('data.meta_connection', null)->assertJsonPath('data.meta_pixel_id', '222222');
        $this->assertSame(0, SocialAccount::where('network', MetaAds::NETWORK)->count());
    }

    public function test_a_pending_connection_belongs_to_the_store_and_person_who_started_it(): void
    {
        $this->fakeMeta();
        $url = $this->storeApi($this->store, 'POST', 'marketing/tracking/meta/connect')->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $key = explode('meta=', $this->get('http://localhost/reseaux/meta/retour?code=abc&state='.rawurlencode($query['state']))->headers->get('Location'))[1];

        $koffi = $this->createStore(['slug' => 'koffi']);
        $this->actingAs($koffi->owner);
        $this->storeApi($koffi, 'GET', "marketing/tracking/meta/{$key}")->assertStatus(422);
        $this->storeApi($koffi, 'POST', "marketing/tracking/meta/{$key}", ['business_id' => '111', 'pixel_id' => 'new', 'catalog_id' => 'new'])->assertStatus(422);
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_facebook_refusal_comes_back_to_the_tracking_page(): void
    {
        $url = $this->storeApi($this->store, 'POST', 'marketing/tracking/meta/connect')->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $back = $this->get('http://localhost/reseaux/meta/retour?error=access_denied&state='.rawurlencode($query['state']))->headers->get('Location');
        $this->assertStringStartsWith($this->storeUrl($this->store, '/gestion/marketing/suivi?erreur='), $back);
    }
}
