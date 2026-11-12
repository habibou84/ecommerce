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
        $this->storeApi($this->store, 'PUT', 'marketing/tracking', ['meta_pixel_id' => '222222', 'ga4_id' => 'G-ABC123XYZ', 'meta_capi_token' => 'EAAB-main-0123456789abcdefghijklmnopqrstuvwxyz'])
            ->assertJsonPath('data.meta_connection.catalog_id', '333333');
        $this->assertSame('EAAB-main-0123456789abcdefghijklmnopqrstuvwxyz', app(MetaAds::class)->conversionsToken($this->store->fresh()));
        $this->storeApi($this->store, 'PUT', 'marketing/tracking', ['meta_pixel_id' => '222222', 'remove_capi_token' => true]);

        // Accès expiré : plus d'envoi par le serveur, l'écran demande de reconnecter
        $account->forceFill(['token_expires_at' => now()->subDay()])->save();
        $this->storeApi($this->store, 'GET', 'marketing/tracking')->assertJsonPath('data.conversions_ready', false)->assertJsonPath('data.meta_connection.expired', true);

        // Déconnexion : le pixel reste
        $this->storeApi($this->store, 'DELETE', 'marketing/tracking/meta')->assertOk()
            ->assertJsonPath('data.meta_connection', null)->assertJsonPath('data.meta_pixel_id', '222222');
        $this->assertSame(0, SocialAccount::where('network', MetaAds::NETWORK)->count());
    }

    public function test_a_system_user_token_finds_its_business_in_the_token(): void
    {
        // Jeton d'utilisateur système (configuration Facebook Login for Business) : me/businesses refusé (#100)
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::sequence()->push(['access_token' => 'system-token'])->push(['error' => ['message' => 'Jeton déjà permanent']], 400),
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['expires_at' => 0, 'granular_scopes' => [
                ['scope' => 'catalog_management', 'target_ids' => ['333333']],
                ['scope' => 'business_management', 'target_ids' => ['111']],
            ]]]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['error' => ['message' => '(#100) Missing Permission', 'code' => 100]], 400),
            'graph.facebook.com/*/111?*' => Http::response(['id' => '111', 'name' => 'Awa SARL']),
        ]);
        $url = $this->storeApi($this->store, 'POST', 'marketing/tracking/meta/connect')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $back = $this->get('http://localhost/reseaux/meta/retour?code=abc&state='.rawurlencode($query['state']))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith($this->storeUrl($this->store, '/gestion/marketing/suivi?meta='), $back);
        $key = explode('meta=', $back)[1];
        $this->storeApi($this->store, 'GET', "marketing/tracking/meta/{$key}")->assertOk()
            ->assertJsonPath('data.businesses', [['id' => '111', 'name' => 'Awa SARL']]);
    }

    public function test_no_business_in_the_token_says_what_to_check(): void
    {
        // Aucun compte Business dans le jeton : l'erreur de Meta, avec ce qu'il faut vérifier
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'system-token']),
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['granular_scopes' => []]]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['error' => ['message' => '(#100) Missing Permission']], 400),
            'graph.facebook.com/*/me?*' => Http::response(['id' => '9']),
        ]);
        $url = $this->storeApi($this->store, 'POST', 'marketing/tracking/meta/connect')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $back = $this->get('http://localhost/reseaux/meta/retour?code=abc&state='.rawurlencode($query['state']))->headers->get('Location');
        $this->assertStringContainsString('erreur=', $back);
        $this->assertStringContainsString('business_management', urldecode($back));
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

    public function test_a_refused_pixel_creation_falls_back_on_an_ad_account_and_a_refused_catalog_says_what_to_do(): void
    {
        $refused = Http::response(['error' => ['message' => 'Unsupported post request. Object with ID \'111\' does not exist', 'code' => 100]], 400);
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::sequence()->push(['access_token' => 'short'])->push(['access_token' => 'long-token']),
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['expires_at' => now()->addDays(60)->timestamp]]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['data' => [['id' => '111', 'name' => 'Joli maison']]]),
            'graph.facebook.com/*/111/owned_pixels*' => Http::response(['data' => []]),
            'graph.facebook.com/*/111/client_pixels*' => Http::response(['data' => []]),
            'graph.facebook.com/*/111/owned_product_catalogs?*' => Http::response(['data' => [['id' => '333333', 'name' => 'Catalogue']]]),
            'graph.facebook.com/*/111/client_product_catalogs*' => Http::response(['data' => []]),
            'graph.facebook.com/*/111/owned_product_catalogs' => $refused,
            'graph.facebook.com/*/111/adspixels' => $refused,
            'graph.facebook.com/*/111/owned_ad_accounts*' => Http::response(['data' => [['id' => 'act_9', 'account_status' => 2], ['id' => 'act_5', 'account_status' => 1]]]),
            'graph.facebook.com/*/111/client_ad_accounts*' => Http::response(['data' => []]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['id' => 'act_5', 'account_status' => 1]]]),
            'graph.facebook.com/*/act_5/adspixels' => Http::response(['id' => '555555']),
            'graph.facebook.com/*/333333/product_feeds*' => Http::response(['data' => []]),
            'graph.facebook.com/*/333333/product_feeds' => Http::response(['id' => '444']),
            'graph.facebook.com/*/333333/external_event_sources' => Http::response(['success' => true]),
        ]);
        $connect = function () {
            $url = $this->storeApi($this->store, 'POST', 'marketing/tracking/meta/connect')->json('url');
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $back = $this->get('http://localhost/reseaux/meta/retour?code=abc&state='.rawurlencode($query['state']))->headers->get('Location');

            return explode('meta=', $back)[1];
        };

        // Nouveau catalogue refusé : on dit quoi faire, et aucun pixel n'est créé pour rien
        $key = $connect();
        $this->storeApi($this->store, 'GET', "marketing/tracking/meta/{$key}?business=111")->assertOk();
        $this->storeApi($this->store, 'POST', "marketing/tracking/meta/{$key}", ['business_id' => '111', 'pixel_id' => 'new', 'catalog_id' => 'new'])
            ->assertStatus(422)->assertSeeText('Choisissez un catalogue existant');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'adspixels'));

        // Catalogue existant, nouveau pixel refusé pour le compte Business : créé sur le compte publicitaire actif
        $this->storeApi($this->store, 'POST', "marketing/tracking/meta/{$key}", ['business_id' => '111', 'pixel_id' => 'new', 'catalog_id' => '333333'])->assertOk()
            ->assertJsonPath('data.meta_pixel_id', '555555')->assertJsonPath('data.meta_connection.catalog_id', '333333');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'act_9/adspixels'));
    }

    public function test_connecting_from_boost_comes_back_to_boost_and_the_installed_app_gets_the_result_without_leaving(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'long-token']),
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['expires_at' => now()->addDays(60)->timestamp]]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['data' => [['id' => '111', 'name' => 'Awa SARL']]]),
        ]);
        $connect = function (array $body) {
            $url = $this->storeApi($this->store, 'POST', 'marketing/tracking/meta/connect', $body)->assertOk()->json('url');
            parse_str(parse_url($url, PHP_URL_QUERY), $query);

            return $this->get('http://localhost/reseaux/meta/retour?code=abc&state='.rawurlencode($query['state']));
        };

        // Navigateur : retour sur le menu Boost avec la connexion en attente
        $back = $connect(['back' => 'boost'])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith($this->storeUrl($this->store, '/gestion/boost?meta='), $back);

        // Application installée : Facebook ouvert par-dessus ; la fenêtre dit « c'est fait », l'application lit le résultat une fois
        $relay = str_repeat('ab12', 12);
        $this->storeApi($this->store, 'GET', "marketing/tracking/meta/relay/{$relay}")->assertOk()->assertJsonPath('data.result', null);
        $connect(['back' => 'boost', 'relay' => $relay])->assertOk()->assertSee('Retourne dans Jibiat Marchand');
        $result = $this->storeApi($this->store, 'GET', "marketing/tracking/meta/relay/{$relay}")->assertOk()->json('data.result');
        $this->assertMatchesRegularExpression('/^meta=[A-Za-z0-9]{40}$/', $result);
        $this->storeApi($this->store, 'GET', 'marketing/tracking/meta/'.substr($result, 5))->assertOk()->assertJsonPath('data.businesses.0.name', 'Awa SARL');
        $this->storeApi($this->store, 'GET', "marketing/tracking/meta/relay/{$relay}")->assertJsonPath('data.result', null);

        // Lancée depuis l'application Android : la page de fin propose d'y retourner
        $connect(['back' => 'boost', 'relay' => str_repeat('cd34', 12), 'app' => true])->assertOk()->assertSee("Retourner dans l'application", false)
            ->assertSee('package=com.jibiat.marchand', false);

        // Une page de retour inconnue est refusée
        $this->storeApi($this->store, 'POST', 'marketing/tracking/meta/connect', ['back' => 'ailleurs'])->assertStatus(422);
    }
}
