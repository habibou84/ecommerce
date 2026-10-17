<?php

namespace Tests\Feature\Billing;

use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounts\LoginHandoff;
use App\Services\Catalog\CatalogWriter;
use App\Services\Couriers\SharedCouriers;
use App\Support\Tenancy;
use Tests\TestCase;

class HostedStoresTest extends TestCase
{
    private Store $mode;

    private Store $kids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mode = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->mode->currentSubscription()->first()->update(['status' => SubscriptionStatus::Expired]);
        $this->mode->subscriptions()->create(['plan_id' => Plan::where('code', 'business')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $this->actingAs($this->mode->owner);

        $this->storeApi($this->mode, 'GET', 'my-stores')->assertJsonPath('data.next_address', 'http://awa.localhost');
        $this->storeApi($this->mode, 'POST', 'my-stores', ['name' => 'Awa Kids', 'path' => 'produits'])->assertStatus(422)->assertJsonPath('message', 'Ce nom de page est réservé.');
        $this->storeApi($this->mode, 'POST', 'my-stores', ['name' => 'Awa Kids', 'path' => 'kids'])->assertCreated()
            ->assertJsonPath('redirect', fn ($url) => str_starts_with($url, 'http://awa.localhost/gestion?bienvenue=1&boutique='));
        $this->kids = Store::where('path', 'kids')->sole();
    }

    private function product(Store $store, string $title): Product
    {
        return SharedCouriers::within($store, fn () => app(CatalogWriter::class)->save($store, null, ['title' => $title, 'variants' => [['price' => 5000, 'on_hand' => 5]]]));
    }

    public function test_a_hosted_store_lives_at_the_address_of_its_host(): void
    {
        $this->assertSame([$this->mode->id, 'http://awa.localhost/kids', 'http://awa.localhost/gestion/commandes?boutique='.$this->kids->id],
            [$this->kids->host_store_id, $this->kids->url(), $this->kids->url('/gestion/commandes')]);

        // Gestion : même adresse, boutique choisie par l'en-tête ; ses produits restent les siens
        $this->storeApi($this->kids, 'GET', 'auth/me')->assertOk()->assertJsonPath('data.store.name', 'Awa Kids')
            ->assertJsonPath('data.store.host_url', 'http://awa.localhost')
            ->assertJsonPath('data.stores', fn ($stores) => array_column($stores, 'name') === ['Awa Mode', 'Awa Kids']);
        $this->storeApi($this->kids, 'POST', 'products', ['title' => 'Body bébé', 'options' => [], 'variants' => [['options' => [], 'price' => 3000, 'on_hand' => 2]]])->assertCreated();
        $this->assertSame(1, Product::forStore($this->kids->id)->count());
        $this->assertSame(0, Product::forStore($this->mode->id)->count());

        // Une boutique d'une autre adresse ne peut pas être choisie : la boutique de l'adresse reste servie
        $other = $this->createStore(['slug' => 'voisin', 'name' => 'Voisin']);
        $this->withHeader(Tenancy::STORE_HEADER, (string) $other->id)->apiOn($this->storeUrl($this->mode), 'GET', 'auth/me')->assertJsonPath('data.store.name', 'Awa Mode');

        // Nom de la page modifiable depuis la gestion de la boutique hébergée
        $this->storeApi($this->kids, 'PUT', 'store/slug', ['slug' => 'enfants'])->assertOk()->assertJsonPath('data.url', 'http://awa.localhost/enfants');
        $this->storeApi($this->kids->fresh(), 'PUT', 'store/slug', ['slug' => 'panier'])->assertStatus(422);
    }

    public function test_the_storefront_of_a_hosted_store_is_a_page_of_the_address(): void
    {
        foreach ([$this->mode, $this->kids] as $store) {
            $store->forceFill(['storefront_enabled' => true])->save();
        }
        $this->product($this->mode, 'Robe wax');
        $body = $this->product($this->kids, 'Body bébé');

        $home = $this->get('http://awa.localhost/kids')->assertOk()->assertSee('Awa Kids')->assertSee('Body bébé')->assertDontSee('Robe wax');
        $html = $home->getContent();
        $this->assertStringContainsString('href="/kids/produits"', $html);
        $this->assertStringContainsString('data-base="/kids"', $html);
        $this->assertStringNotContainsString('href="/produits"', $html);
        $this->assertStringNotContainsString('/kids/build/', $html);

        $this->get("http://awa.localhost/kids/produits/{$body->slug}")->assertOk()->assertSee('Body bébé');
        $this->get('http://awa.localhost/')->assertOk()->assertSee('Robe wax')->assertDontSee('Body bébé');

        // Panier propre à la page, redirections préfixées
        $added = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->postJson('http://awa.localhost/kids/panier/articles', ['variant_id' => $body->variants[0]->id, 'quantity' => 1])->assertOk();
        $this->assertNotNull($added->getCookie('panier_kids'));
        $this->assertNull($added->getCookie('panier'));
        $this->get('http://awa.localhost/kids/commande')->assertRedirect('http://awa.localhost/kids/panier');
    }

    public function test_an_attached_store_moves_to_the_address_and_its_old_link_follows(): void
    {
        $shoes = $this->createStore(['slug' => 'awa-shoes', 'name' => 'Awa Shoes', 'owner_id' => $this->mode->owner_id]);
        // Avant d'être réunie : pas dans « Autres comptes » (ses propres boutiques se réunissent depuis la vue d'ensemble)
        $this->storeApi($this->mode, 'GET', 'auth/me')->assertJsonPath('data.other_stores', [])->assertJsonPath('data.owns_several', true);
        $this->storeApi($this->mode, 'POST', "my-stores/{$shoes->id}/attach")->assertOk();
        $shoes->refresh();
        $this->assertSame([$this->mode->id, 'shoes'], [$shoes->host_store_id, $shoes->path]);

        $this->get('http://awa-shoes.localhost/produits?page=2')->assertRedirect('http://awa.localhost/shoes/produits?page=2');
        $this->get('http://awa-shoes.localhost/gestion')->assertRedirect('http://awa.localhost/gestion?boutique='.$shoes->id);
        $this->getJson('http://awa-shoes.localhost/api/v1/auth/me')->assertNotFound();

        // Retirée : elle retrouve sa propre adresse
        $this->storeApi($this->mode, 'DELETE', "my-stores/{$shoes->id}/attach")->assertOk();
        $this->assertSame('http://awa-shoes.localhost/', $shoes->fresh()->url());
    }

    public function test_people_of_a_hosted_store_sign_in_at_the_address(): void
    {
        $agent = User::factory()->create(['phone' => '+2250102030405', 'password' => 'secret123']);
        $this->addMember($this->kids, $agent, Role::Manager);
        $this->app['auth']->forgetGuards();

        // Elle ne travaille que pour Awa Kids : la connexion à l'adresse ouvre Awa Kids
        $this->apiOn($this->storeUrl($this->mode), 'POST', 'auth/login', ['phone' => '0102030405', 'password' => 'secret123'])->assertOk()
            ->assertJsonPath('data.store.name', 'Awa Kids')->assertJsonPath('data.stores', fn ($stores) => array_column($stores, 'name') === ['Awa Kids']);

        // Lien de connexion vers une boutique hébergée : consommé à l'adresse, gestion ouverte sur elle
        $url = app(LoginHandoff::class)->url($agent, $this->kids, '/gestion');
        $this->assertStringStartsWith('http://awa.localhost/auth/relais?jeton=', $url);
        $this->get($url)->assertRedirect('/gestion?boutique='.$this->kids->id);
    }
}
