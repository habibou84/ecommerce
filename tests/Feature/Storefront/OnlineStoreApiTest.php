<?php

namespace Tests\Feature\Storefront;

use App\Enums\Role;
use App\Models\Collection;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnlineStoreApiTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->actingAs($this->store->owner);
    }

    public function test_opening_requires_a_product_and_toggles_the_public_site(): void
    {
        $this->storeApi($this->store, 'GET', 'online-store')->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.theme.code', 'classique')
            ->assertJsonPath('data.checklist.products', 0)->assertJsonCount(2, 'data.themes');

        $this->storeApi($this->store, 'PATCH', 'online-store', ['enabled' => true])->assertStatus(422)->assertJsonValidationErrors('enabled');

        app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 2]]]);
        $this->storeApi($this->store, 'PATCH', 'online-store', ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
        $this->get($this->storeUrl($this->store, '/produits/robe-wax'))->assertOk()->assertSee('Robe wax');

        $this->storeApi($this->store, 'PATCH', 'online-store', ['enabled' => false])->assertOk();
        $this->withHeader('Accept', 'text/html')->get($this->storeUrl($this->store, '/produits/robe-wax'))->assertSee('ouvre bientôt');
    }

    public function test_theme_settings_are_validated_against_the_schema(): void
    {
        $collection = Collection::create(['store_id' => $this->store->id, 'title' => 'Robes', 'slug' => 'robes']);

        $this->storeApi($this->store, 'PUT', 'online-store/theme', ['settings' => [
            'primary_color' => '#7C3AED', 'hero_title' => '  Nouvelle collection  ', 'show_new_arrivals' => false,
            'featured_collection_id' => $collection->id, 'inconnu' => 'x', 'hero_image' => 'stores/1/autre',
        ]])->assertOk()
            ->assertJsonPath('data.theme.settings.primary_color', '#7c3aed')
            ->assertJsonPath('data.theme.settings.hero_title', 'Nouvelle collection')
            ->assertJsonPath('data.theme.settings.show_new_arrivals', false)
            ->assertJsonPath('data.theme.settings.featured_collection_id', $collection->id)
            ->assertJsonPath('data.theme.settings.hero_image', null)
            ->assertJsonMissingPath('data.theme.settings.inconnu');

        $this->storeApi($this->store, 'PUT', 'online-store/theme', ['settings' => ['primary_color' => 'rouge']])
            ->assertStatus(422)->assertJsonValidationErrors('primary_color');
        $this->storeApi($this->store, 'PUT', 'online-store/theme', ['settings' => ['font' => 'comic']])->assertStatus(422);

        // Changement de thème : textes gardés, couleurs du nouveau thème
        $this->storeApi($this->store, 'POST', 'online-store/theme/activate', ['code' => 'marche'])->assertOk()
            ->assertJsonPath('data.theme.code', 'marche')
            ->assertJsonPath('data.theme.settings.primary_color', '#c2410c')
            ->assertJsonPath('data.theme.settings.hero_title', 'Nouvelle collection');
        $this->storeApi($this->store, 'POST', 'online-store/theme/activate', ['code' => 'inconnu'])->assertStatus(422);

        // Retour au premier thème : ses réglages sont retrouvés
        $this->storeApi($this->store, 'POST', 'online-store/theme/activate', ['code' => 'classique'])->assertOk()
            ->assertJsonPath('data.theme.settings.primary_color', '#7c3aed');
    }

    public function test_logo_and_hero_image_uploads(): void
    {
        $response = $this->storeApi($this->store, 'POST', 'online-store/logo', ['image' => UploadedFile::fake()->image('logo.png', 600, 600)])->assertOk();
        $this->assertStringContainsString('/branding/', $response->json('data.logo_url'));
        $path = $this->store->refresh()->logo_path;
        Storage::disk('public')->assertExists("{$path}/thumb.webp");

        $this->storeApi($this->store, 'POST', 'online-store/logo', ['image' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertStatus(422);

        $this->storeApi($this->store, 'DELETE', 'online-store/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        Storage::disk('public')->assertMissing("{$path}/thumb.webp");

        $hero = $this->storeApi($this->store, 'POST', 'online-store/theme/hero-image', ['image' => UploadedFile::fake()->image('accueil.jpg', 1600, 900)])->assertOk();
        $this->assertStringContainsString('/theme/', $hero->json('data.theme.hero_image_url'));
        $this->storeApi($this->store, 'DELETE', 'online-store/theme/hero-image')->assertOk()->assertJsonPath('data.theme.hero_image_url', null);
    }

    public function test_preview_link(): void
    {
        $url = $this->storeApi($this->store, 'POST', 'online-store/preview')->assertOk()->json('data.url');
        $this->assertStringStartsWith('http://awa.localhost/apercu?boutique='.$this->store->id, $url);

        $this->logout();
        $this->get($url)->assertRedirect('/')->assertCookie('apercu');
    }

    public function test_pages_crud_with_unique_slugs(): void
    {
        $first = $this->storeApi($this->store, 'POST', 'pages', ['title' => 'Livraison et retours', 'body' => 'Sous 24 h.'])
            ->assertCreated()->assertJsonPath('data.slug', 'livraison-et-retours')->json('data.id');
        $this->storeApi($this->store, 'POST', 'pages', ['title' => 'Livraison et retours'])->assertCreated()->assertJsonPath('data.slug', 'livraison-et-retours-2');
        $this->storeApi($this->store, 'POST', 'pages', ['body' => 'x'])->assertStatus(422)->assertJsonValidationErrors('title');

        $this->storeApi($this->store, 'PUT', "pages/{$first}", ['slug' => 'Livraison !', 'is_visible' => false])->assertOk()
            ->assertJsonPath('data.slug', 'livraison')->assertJsonPath('data.is_visible', false);
        $this->storeApi($this->store, 'GET', 'pages')->assertOk()->assertJsonCount(2, 'data');

        $other = $this->createStore(['slug' => 'autre']);
        $foreign = Page::create(['store_id' => $other->id, 'title' => 'Autre', 'slug' => 'autre']);
        $this->storeApi($this->store, 'PUT', "pages/{$foreign->id}", ['title' => 'Pris'])->assertNotFound();

        $this->storeApi($this->store, 'DELETE', "pages/{$first}")->assertOk();
        $this->assertSame(1, Page::forStore($this->store->id)->count());
    }

    public function test_menus_are_validated_and_shown_on_the_storefront(): void
    {
        $this->store->forceFill(['storefront_enabled' => true])->save();
        $page = Page::create(['store_id' => $this->store->id, 'title' => 'À propos', 'slug' => 'a-propos']);
        $collection = Collection::create(['store_id' => $this->store->id, 'title' => 'Robes', 'slug' => 'robes', 'is_visible_online' => true]);

        $this->storeApi($this->store, 'GET', 'menus')->assertOk()->assertJsonPath('data.main.custom', false);

        $this->storeApi($this->store, 'PUT', 'menus/main', ['items' => [
            ['label' => 'Accueil', 'type' => 'home'],
            ['label' => 'Nos robes', 'type' => 'collection', 'target' => $collection->id],
            ['label' => 'Qui sommes-nous', 'type' => 'page', 'target' => $page->id],
            ['label' => 'Instagram', 'type' => 'url', 'target' => 'https://instagram.com/awamode'],
        ]])->assertOk()->assertJsonPath('data.main.custom', true)->assertJsonCount(4, 'data.main.items');

        $this->storeApi($this->store, 'PUT', 'menus/main', ['items' => [['label' => 'Piège', 'type' => 'url', 'target' => 'javascript:alert(1)']]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.target');
        $this->storeApi($this->store, 'PUT', 'menus/main', ['items' => [['label' => 'X', 'type' => 'page', 'target' => 99999]]])->assertStatus(422);
        $this->storeApi($this->store, 'PUT', 'menus/cote', ['items' => []])->assertNotFound();

        $this->get($this->storeUrl($this->store))->assertOk()
            ->assertSee('href="/collections/robes"', false)->assertSee('Nos robes')->assertSee('href="/pages/a-propos"', false)
            ->assertSee('href="https://instagram.com/awamode"', false);

        $this->storeApi($this->store, 'DELETE', 'menus/main')->assertOk()->assertJsonPath('data.main.custom', false);
        $this->assertSame(0, Menu::count());
    }

    public function test_only_people_who_manage_the_store_can_change_the_online_store(): void
    {
        $agent = $this->addMember($this->store, User::factory()->create(), Role::ConfirmationAgent);
        $this->logout();
        $this->actingAs($agent);

        $this->storeApi($this->store, 'GET', 'online-store')->assertForbidden();
        $this->storeApi($this->store, 'POST', 'pages', ['title' => 'x'])->assertForbidden();
        $this->storeApi($this->store, 'PUT', 'menus/main', ['items' => []])->assertForbidden();
    }
}
