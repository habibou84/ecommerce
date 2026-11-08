<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StoreStatus;
use App\Models\SlugHistory;
use App\Models\StoreDomain;
use App\Models\StoreInvitation;
use App\Services\Live\LiveBilling;
use App\Support\StartSteps;
use App\Support\Tenancy;
use App\Support\TrialOffer;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    public function test_the_platform_address_shows_the_presentation_page(): void
    {
        $this->get($this->platformUrl())->assertOk()->assertSee('Créer mon espace marchand');
        $this->get('http://www.localhost/')->assertOk()->assertSee('Créer mon espace marchand');
    }

    public function test_the_presentation_page_says_the_live_stays_on_tiktok(): void
    {
        $this->get($this->platformUrl())->assertOk()
            ->assertSee('Toi, tu fais ton live sur TikTok.')
            ->assertSee('Jibiat Marchand enregistre les commandes.')
            ->assertSee('Mon live se fait sur Jibiat Marchand ?')
            ->assertSee('/videos/live/probleme-solution-v.mp4', false)
            ->assertSee('/videos/live/comment-ca-marche-v.mp4', false)
            ->assertSee('/videos/og-marchand.jpg', false)
            ->assertSee('https://wa.me/2250507016739?text=', false)
            ->assertSee('"@type":"FAQPage"', false)
            ->assertDontSee('Essai gratuit');
    }

    public function test_live_prices_show_only_when_paid_lives_are_on(): void
    {
        $this->get($this->platformUrl())->assertOk()->assertSee('application est gratuite')->assertDontSee('Live de 11 à 30 commandes');

        app(LiveBilling::class)->update(['paid' => true]);
        $this->get($this->platformUrl())->assertOk()
            ->assertSee('Tes 300 premières commandes en live')
            ->assertSee('Live de 11 à 30 commandes')
            ->assertSee("500\u{00A0}F", false)->assertSee("7\u{00A0}000\u{00A0}F", false);
    }

    public function test_the_trial_is_announced_in_days_when_it_is_not_whole_months(): void
    {
        config(['platform.trial_days' => 14]);
        $this->assertSame('14 jours', TrialOffer::duration());
        config(['platform.trial_days' => 60]);
        $this->assertSame('2 mois', TrialOffer::duration());
    }

    public function test_the_whatsapp_facebook_window_is_only_on_the_platform(): void
    {
        $store = $this->createStore();

        // Seul domaine déclaré chez Meta pour le SDK : la fenêtre Facebook de toutes les boutiques s'ouvre ici
        $this->get($this->platformUrl('/whatsapp/facebook'))->assertOk();
        $this->get($this->storeUrl($store, '/whatsapp/facebook'))->assertNotFound();
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

    public function test_the_store_management_can_be_installed_on_the_home_screen(): void
    {
        $store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode Abidjan']);

        $this->get($this->storeUrl($store, '/gestion'))->assertOk()
            ->assertSee('<link rel="manifest" href="/gestion/manifest.webmanifest">', false)
            ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false);
        $this->get($this->storeUrl($store, '/gestion/manifest.webmanifest'))->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Awa Mode Abidjan · Jibiat Marchand')
            ->assertJsonPath('short_name', 'Awa Mode Abi')
            ->assertJsonPath('start_url', '/gestion')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('icons.1.sizes', '512x512');
        // La plateforme n'a pas de gestion à installer
        $this->get($this->platformUrl('/connexion'))->assertDontSee('/gestion/manifest.webmanifest', false);
    }

    public function test_start_page_lists_the_essential_steps_in_video(): void
    {
        $this->get($this->platformUrl('/'))->assertOk()->assertSee('href="/bien-demarrer"', false);
        $page = $this->get($this->platformUrl('/bien-demarrer'))->assertOk()
            ->assertSee('Les étapes essentielles pour commencer')
            ->assertSee('Étape 1 sur 11')
            ->assertSee('Installer l\'application sur votre téléphone')
            ->assertSee('/videos/etapes/etape-11.mp4', false);
        foreach (StartSteps::all() as $step) {
            $this->assertFileExists(public_path(ltrim($step['video'], '/')));
            $this->assertFileExists(public_path(ltrim($step['poster'], '/')));
        }
        $this->assertSame(11, substr_count($page->getContent(), '<video'));
    }
}
