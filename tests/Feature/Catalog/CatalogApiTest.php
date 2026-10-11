<?php

namespace Tests\Feature\Catalog;

use App\Enums\Role;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore();
        $this->actingAs($this->store->owner);
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->storeApi($this->store, $method, $path, $data);
    }

    private function robe(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Robe wax',
            'description' => 'Coupe évasée',
            'options' => [['name' => 'Taille', 'values' => ['S', 'M']]],
            'variants' => [
                ['options' => ['S'], 'price' => 15000, 'sku' => 'RW-S', 'on_hand' => 2],
                ['options' => ['M'], 'price' => 16000, 'sku' => 'RW-M', 'on_hand' => 5],
            ],
            'tags' => ['wax'],
        ], $overrides);
    }

    public function test_a_product_is_created_read_updated_and_deleted(): void
    {
        $created = $this->api('POST', 'products', $this->robe())->assertCreated()
            ->assertJsonPath('data.title', 'Robe wax')
            ->assertJsonPath('data.variants.1.options', ['M'])
            ->assertJsonPath('data.variants.1.on_hand', 5)
            ->assertJsonPath('data.tags', ['wax'])
            ->json('data');

        $this->assertSame('http://'.$this->store->slug.'.localhost/produits/robe-wax', $created['url']);

        $this->api('GET', "products/{$created['id']}")->assertOk()->assertJsonPath('data.price_max', 16000);

        $update = $this->robe(['title' => 'Robe wax longue']);
        $update['variants'][0]['id'] = $created['variants'][0]['id'];
        $update['variants'][1]['id'] = $created['variants'][1]['id'];
        unset($update['variants'][0]['on_hand'], $update['variants'][1]['on_hand']);
        $update['variants'][1]['price'] = 17000;
        $this->api('PUT', "products/{$created['id']}", $update)->assertOk()
            ->assertJsonPath('data.title', 'Robe wax longue')
            ->assertJsonPath('data.slug', 'robe-wax')
            ->assertJsonPath('data.variants.1.price', 17000)
            ->assertJsonPath('data.variants.1.on_hand', 5);

        $this->api('DELETE', "products/{$created['id']}")->assertOk();
        $this->api('GET', "products/{$created['id']}")->assertNotFound();
    }

    public function test_validation_and_business_errors_point_to_fields(): void
    {
        $this->api('POST', 'products', ['title' => '', 'options' => [], 'variants' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['title', 'variants']);

        $this->api('POST', 'products', $this->robe());
        $this->api('POST', 'products', $this->robe(['title' => 'Autre']))
            ->assertStatus(422)->assertJsonValidationErrors('variants.0.sku');
    }

    public function test_the_list_searches_filters_and_shows_stock(): void
    {
        $this->api('POST', 'products', $this->robe());
        $this->api('POST', 'products', ['title' => 'Sac', 'options' => [], 'variants' => [['price' => 9000, 'sku' => 'SAC', 'on_hand' => 0]]]);
        $this->api('POST', 'products', ['title' => 'Collier', 'status' => 'draft', 'options' => [], 'variants' => [['price' => 3000, 'on_hand' => 2]]]);

        $all = $this->api('GET', 'products')->assertOk()->json('data');
        $this->assertCount(3, $all);
        $robe = collect($all)->firstWhere('title', 'Robe wax');
        $this->assertSame([2, 7, 7], [$robe['variants_count'], $robe['on_hand'], $robe['available']]);

        $this->assertSame(['Sac'], array_column($this->api('GET', 'products?search=sac')->json('data'), 'title'));
        $this->assertSame(['Robe wax'], array_column($this->api('GET', 'products?search=RW-M')->json('data'), 'title'));
        $this->assertSame(['Collier'], array_column($this->api('GET', 'products?status=draft')->json('data'), 'title'));
        $this->assertSame(['Sac'], array_column($this->api('GET', 'products?stock=out')->json('data'), 'title'));
        $this->assertEqualsCanonicalizing(['Robe wax', 'Collier'], array_column($this->api('GET', 'products?stock=low')->json('data'), 'title'));
    }

    public function test_photos_are_uploaded_ordered_and_linked_to_variants(): void
    {
        Storage::fake('public');
        $product = $this->api('POST', 'products', $this->robe())->json('data');

        $first = $this->withHeaders(['Referer' => $this->storeUrl($this->store)])
            ->post($this->storeUrl($this->store, "/api/v1/products/{$product['id']}/images"), ['image' => UploadedFile::fake()->image('a.jpg', 1000, 1000)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $second = $this->withHeaders(['Referer' => $this->storeUrl($this->store)])
            ->post($this->storeUrl($this->store, "/api/v1/products/{$product['id']}/images"), ['image' => UploadedFile::fake()->image('b.png', 500, 500)], ['Accept' => 'application/json'])
            ->json('data');
        $this->assertStringEndsWith('/thumb.webp', $first['urls']['thumb']);

        $this->api('PUT', "products/{$product['id']}/images/order", ['ids' => [$second['id'], $first['id']]])->assertOk();

        $update = $this->robe();
        $update['variants'][1]['id'] = $product['variants'][1]['id'];
        $update['variants'][1]['image_id'] = $first['id'];
        $data = $this->api('PUT', "products/{$product['id']}", $update)->json('data');
        $this->assertSame([$second['id'], $first['id']], array_column($data['images'], 'id'));
        $this->assertSame($first['id'], $data['variants'][1]['image_id']);

        $this->api('DELETE', "products/{$product['id']}/images/{$first['id']}")->assertOk();
        $this->assertNull($this->api('GET', "products/{$product['id']}")->json('data.variants.1.image_id'));
    }

    public function test_duplicating_a_product_makes_a_draft_without_skus(): void
    {
        $product = $this->api('POST', 'products', $this->robe())->json('data');

        $copy = $this->api('POST', "products/{$product['id']}/duplicate")->assertCreated()->json('data');

        $this->assertSame(['Robe wax (copie)', 'draft', 'robe-wax-copie'], [$copy['title'], $copy['status'], $copy['slug']]);
        $this->assertSame([null, null], array_column($copy['variants'], 'sku'));
        $this->assertSame([0, 0], array_column($copy['variants'], 'on_hand'));
    }

    public function test_collections_hold_ordered_products(): void
    {
        $a = $this->api('POST', 'products', $this->robe())->json('data.id');
        $b = $this->api('POST', 'products', ['title' => 'Sac', 'options' => [], 'variants' => [['price' => 1]]])->json('data.id');

        $collection = $this->api('POST', 'collections', ['title' => 'Nouveautés', 'product_ids' => [$b, $a]])->assertCreated()
            ->assertJsonPath('data.slug', 'nouveautes')->assertJsonPath('data.products_count', 2)->json('data');

        $this->assertSame([$b, $a], array_column($this->api('GET', "collections/{$collection['id']}")->json('data.products'), 'id'));
        $this->assertSame([$a], array_column($this->api('GET', "products?collection_id={$collection['id']}&search=robe")->json('data'), 'id'));

        $this->api('PUT', "collections/{$collection['id']}", ['title' => 'Nouveautés', 'product_ids' => [999999]])->assertStatus(422);
    }

    public function test_csv_export_and_import_through_the_api(): void
    {
        $this->api('POST', 'products', $this->robe());

        $export = $this->withHeaders(['Referer' => $this->storeUrl($this->store)])->get($this->storeUrl($this->store, '/api/v1/catalog/export'));
        $export->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('RW-M;16000', $export->getContent());

        $file = UploadedFile::fake()->createWithContent('catalogue.csv', "Nom;Prix;Stock\nCeinture;4000;6\n");
        $dry = $this->withHeaders(['Referer' => $this->storeUrl($this->store)])
            ->post($this->storeUrl($this->store, '/api/v1/catalog/import'), ['file' => $file, 'dry_run' => '1'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.dry_run', true)->assertJsonPath('data.products_created', 1);
        $this->assertSame(1, Product::forStore($this->store->id)->count());
    }

    public function test_delivery_app_catalog_preview_and_import(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 1, 'sku' => 'S1', 'name' => 'Sac', 'price' => 9000, 'levels' => [['hub_id' => null, 'on_hand' => 4]]]], 'links' => ['next' => null]])]);

        $this->api('POST', 'catalog/import/delivery-app/preview', ['address' => 'ma-livraison.jibiat.com', 'api_key' => 'lv_x'])
            ->assertOk()->assertJsonPath('data.0.at_merchant', 4);
        $this->api('POST', 'catalog/import/delivery-app', ['address' => 'ma-livraison.jibiat.com', 'api_key' => 'lv_x'])
            ->assertOk()->assertJsonPath('data.created', 1);
        $this->api('POST', 'catalog/import/delivery-app/preview', ['address' => 'exemple.com', 'api_key' => 'lv_x'])
            ->assertStatus(422)->assertJsonValidationErrors('address');
    }

    public function test_permissions_and_store_isolation(): void
    {
        $product = $this->api('POST', 'products', $this->robe())->json('data');

        // Agent de confirmation : lecture seule ; caissier : rien
        $this->actingAs($this->addMember($this->store, null, Role::ConfirmationAgent));
        $this->api('GET', 'products')->assertOk();
        $this->api('PUT', "products/{$product['id']}", $this->robe())->assertForbidden();
        $this->actingAs($this->addMember($this->store, null, Role::Cashier));
        $this->api('GET', 'products')->assertForbidden();

        // Une autre boutique ne voit pas ce produit
        $other = $this->createStore();
        $this->actingAs($other->owner);
        $this->storeApi($other, 'GET', "products/{$product['id']}")->assertNotFound();
        $this->storeApi($other, 'DELETE', "products/{$product['id']}")->assertNotFound();
        $this->assertSame([], $this->storeApi($other, 'GET', 'products')->json('data'));
    }
}
