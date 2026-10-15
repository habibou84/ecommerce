<?php

namespace Tests\Feature;

use App\Http\Middleware\PreprodGate;
use Tests\TestCase;

class PreprodGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.preprod_access_code' => 'mangue-2026']);
    }

    public function test_every_page_asks_for_the_access_code(): void
    {
        $store = $this->createStore();

        $this->get($this->platformUrl('/connexion'))->assertUnauthorized()->assertSee("Saisissez le code d'accès", false)->assertHeader('Cache-Control');
        $this->get($this->storeUrl($store, '/gestion'))->assertUnauthorized()->assertSee('Préproduction');
        $this->getJson($this->storeUrl($store, '/api/v1/store'))->assertForbidden()->assertJson(['message' => 'Préproduction : saisissez le code d\'accès.']);
    }

    public function test_the_right_code_opens_the_site_for_thirty_days(): void
    {
        $store = $this->createStore();

        $this->post($this->platformUrl('/'.PreprodGate::PATH), ['code' => 'faux', 'retour' => '/connexion'])->assertForbidden()->assertSee('Code incorrect.');

        $response = $this->post($this->platformUrl('/'.PreprodGate::PATH), ['code' => 'mangue-2026', 'retour' => '/connexion'])
            ->assertRedirect('/connexion')->assertCookie(PreprodGate::COOKIE, null, false);
        $cookie = $response->getCookie(PreprodGate::COOKIE, false);
        $this->assertTrue($cookie->getExpiresTime() > now()->addDays(29)->getTimestamp());

        $this->withUnencryptedCookie(PreprodGate::COOKIE, $cookie->getValue())->get($this->platformUrl('/connexion'))->assertOk();
        $this->withUnencryptedCookie(PreprodGate::COOKIE, $cookie->getValue())->get($this->storeUrl($store))->assertOk();
        $this->withUnencryptedCookie(PreprodGate::COOKIE, 'invente')->get($this->storeUrl($store))->assertUnauthorized();
    }

    public function test_the_code_is_shared_by_every_store_of_the_platform(): void
    {
        config(['platform.domain' => 'preprod.jibiat.test']);

        $cookie = $this->post('http://preprod.jibiat.test/'.PreprodGate::PATH, ['code' => 'mangue-2026'])->getCookie(PreprodGate::COOKIE, false);
        $this->assertSame('.preprod.jibiat.test', $cookie->getDomain());
    }

    public function test_the_return_address_stays_on_the_site(): void
    {
        $this->post($this->platformUrl('/'.PreprodGate::PATH), ['code' => 'mangue-2026', 'retour' => '//pirate.example/x'])->assertRedirect('/');
    }

    public function test_webhooks_and_health_check_stay_open(): void
    {
        $this->get($this->platformUrl('/up'))->assertOk();
        $this->get('http://api.localhost/webhooks/whatsapp?hub_mode=subscribe')->assertStatus(403)->assertDontSee('Saisissez le code', false);
    }

    public function test_the_gate_is_off_without_a_code_or_in_production(): void
    {
        config(['platform.preprod_access_code' => '']);
        $this->get($this->platformUrl('/connexion'))->assertOk();

        config(['platform.preprod_access_code' => 'mangue-2026']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->get($this->platformUrl('/connexion'))->assertOk();
    }
}
