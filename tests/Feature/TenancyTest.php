<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StoreStatus;
use App\Models\SlugHistory;
use App\Models\StoreDomain;
use App\Models\StoreInvitation;
use App\Support\Tenancy;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    public function test_the_platform_address_shows_the_presentation_page(): void
    {
        $this->get($this->platformUrl())->assertOk()->assertSee('Créer ma boutique');
        $this->get('http://www.localhost/')->assertOk()->assertSee('Créer ma boutique');
    }

    public function test_a_store_subdomain_shows_its_storefront(): void
    {
        $store = $this->createStore(['name' => 'Awa Mode', 'whatsapp_phone' => '+2250707070707']);

        $this->get($this->storeUrl($store))->assertOk()->assertSee('Awa Mode')->assertSee('wa.me/2250707070707', false);
    }

    public function test_an_unknown_subdomain_returns_404(): void
    {
        $this->get($this->storeUrl('inconnue'))->assertNotFound()->assertSee('Aucune boutique à cette adresse.');
        $this->getJson($this->storeUrl('inconnue', '/api/v1/store'))->assertNotFound()->assertJson(['message' => 'Aucune boutique à cette adresse.']);
    }

    public function test_a_suspended_or_closed_store_is_unavailable(): void
    {
        $suspended = $this->createStore(['status' => StoreStatus::Suspended]);
        $closed = $this->createStore(['status' => StoreStatus::Closed]);

        $this->get($this->storeUrl($suspended))->assertForbidden();
        $this->get($this->storeUrl($closed, '/gestion'))->assertForbidden();
    }

    public function test_trial_and_past_due_stores_stay_open(): void
    {
        $this->get($this->storeUrl($this->createStore(['status' => StoreStatus::Trial])))->assertOk();
        $this->get($this->storeUrl($this->createStore(['status' => StoreStatus::PastDue])))->assertOk();
    }

    public function test_an_old_subdomain_redirects_to_the_new_one_until_it_expires(): void
    {
        $store = $this->createStore(['slug' => 'nouvelle']);
        $history = SlugHistory::create(['store_id' => $store->id, 'old_slug' => 'ancienne', 'expires_at' => now()->addDays(10)]);

        $this->get($this->storeUrl('ancienne', '/gestion/equipe?x=1'))->assertStatus(301)
            ->assertRedirect('http://nouvelle.localhost/gestion/equipe?x=1');

        $history->update(['expires_at' => now()->subDay()]);
        $this->get($this->storeUrl('ancienne'))->assertNotFound();
    }

    public function test_a_verified_custom_domain_serves_only_the_storefront(): void
    {
        $store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        StoreDomain::create(['store_id' => $store->id, 'hostname' => 'www.awamode.ci', 'verification_token' => 'x'])
            ->forceFill(['verified_at' => now()])->save();

        $this->get('http://www.awamode.ci/')->assertOk()->assertSee('Awa Mode');
        $this->get('http://www.awamode.ci/gestion/equipe')->assertRedirect('http://awa.localhost/gestion/equipe');
        $this->getJson('http://www.awamode.ci/api/v1/store')->assertNotFound();
    }

    public function test_an_unverified_or_unknown_custom_domain_returns_404(): void
    {
        $store = $this->createStore();
        StoreDomain::create(['store_id' => $store->id, 'hostname' => 'boutique.ci', 'verification_token' => 'x']);

        $this->get('http://boutique.ci/')->assertNotFound();
        $this->get('http://autre.ci/')->assertNotFound();
    }

    public function test_reserved_subdomains_are_not_stores(): void
    {
        $this->get('http://admin.localhost/')->assertRedirect('/console');
        $this->get('http://admin.localhost/console')->assertOk();
        $this->getJson('http://api.localhost/')->assertNotFound();
    }

    public function test_each_space_is_only_served_on_its_address(): void
    {
        $store = $this->createStore();

        $this->get($this->storeUrl($store, '/creer-ma-boutique'))->assertNotFound();
        $this->get($this->platformUrl('/gestion'))->assertNotFound();
        $this->get($this->storeUrl($store, '/gestion'))->assertOk()->assertSee('__BOOT__', false);
        $this->get($this->platformUrl('/creer-ma-boutique'))->assertOk();
    }

    public function test_store_scoped_models_only_see_the_current_store(): void
    {
        $a = $this->createStore();
        $b = $this->createStore();
        foreach ([$a, $b] as $store) {
            StoreInvitation::create([
                'store_id' => $store->id, 'phone' => '+2250707070707', 'role' => Role::Manager,
                'token_hash' => hash('sha256', $store->slug), 'expires_at' => now()->addDay(),
            ]);
        }

        app(Tenancy::class)->setStore($a);
        $this->assertSame([$a->id], StoreInvitation::pluck('store_id')->all());

        // Création sans store_id : celui de la boutique courante
        $created = StoreInvitation::create([
            'phone' => '+2250707070708', 'role' => Role::Cashier, 'token_hash' => 'h', 'expires_at' => now()->addDay(),
        ]);
        $this->assertSame($a->id, $created->store_id);

        $this->assertCount(1, StoreInvitation::forStore($b->id)->get());
    }

    public function test_store_urls_keep_the_scheme_and_port_of_app_url(): void
    {
        config(['app.url' => 'https://jibiat-marchand.com', 'platform.domain' => 'jibiat-marchand.com']);
        $this->assertSame('https://awa.jibiat-marchand.com/gestion', Tenancy::storeUrl('awa', '/gestion'));

        config(['app.url' => 'http://localhost:8000', 'platform.domain' => 'localhost']);
        $this->assertSame('http://awa.localhost:8000/', Tenancy::storeUrl('awa'));
        $this->assertSame('http://localhost:8000/connexion', Tenancy::platformUrl('connexion'));
    }
}
