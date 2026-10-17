<?php

namespace Tests\Feature\Billing;

use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreCatalogTest extends TestCase
{
    private Store $mode;

    private Store $kids;

    private int $kidsSummer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->mode = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode'], 'business');
        $this->actingAs($this->mode->owner);
        $this->storeApi($this->mode, 'POST', 'my-stores', ['name' => 'Awa Kids', 'path' => 'kids'])->assertCreated();
        $this->kids = Store::where('path', 'kids')->sole();
    }

    private function robe(): Product
    {
        $collection = $this->storeApi($this->mode, 'POST', 'collections', ['title' => 'Été'])->json('data.id');
        $this->kidsSummer = $this->storeApi($this->kids, 'POST', 'collections', ['title' => 'été'])->assertCreated()->json('data.id');
        $id = $this->storeApi($this->mode, 'POST', 'products', [
            'title' => 'Robe wax', 'description' => 'Coton', 'options' => [['name' => 'Taille', 'values' => ['S', 'M']]],
            'variants' => [
                ['options' => ['S'], 'price' => 12000, 'cost_price' => 6000, 'sku' => 'RW-S', 'on_hand' => 10],
                ['options' => ['M'], 'price' => 13000, 'sku' => 'RW-M', 'on_hand' => 3],
            ],
            'collection_ids' => [$collection], 'tags' => ['wax'],
        ])->assertCreated()->json('data.id');

        $product = Product::findOrFail($id);
        $stored = app(ImageStore::class)->store(UploadedFile::fake()->image('robe.jpg', 600, 600), $this->mode->id);
        $image = ProductImage::create(['store_id' => $this->mode->id, 'product_id' => $id, 'position' => 1, ...$stored]);
        $product->variants()->first()->update(['image_id' => $image->id]);

        return $product;
    }

    public function test_a_product_is_copied_to_another_store_of_the_address(): void
    {
        $robe = $this->robe();
        $this->storeApi($this->mode, 'GET', "products/{$robe->id}/stores")
            ->assertJsonPath('data', [['id' => $this->kids->id, 'name' => 'Awa Kids', 'product_id' => null, 'can_copy' => true]]);

        $copied = $this->storeApi($this->mode, 'POST', "products/{$robe->id}/copy", ['store_id' => $this->kids->id])->assertCreated()
            ->assertJsonPath('message', '« Robe wax » est maintenant aussi dans Awa Kids (stock à zéro).');
        $copy = Product::forStore($this->kids->id)->findOrFail($copied->json('data.id'));

        // Fiche, options, variantes, prix, SKU, collection de même nom, étiquette ; stock à zéro
        $this->storeApi($this->kids, 'GET', "products/{$copy->id}")->assertOk()
            ->assertJsonPath('data.title', 'Robe wax')->assertJsonPath('data.description', 'Coton')
            ->assertJsonPath('data.variants.*.sku', ['RW-S', 'RW-M'])->assertJsonPath('data.variants.*.price', [12000, 13000])
            ->assertJsonPath('data.collection_ids', [$this->kidsSummer])->assertJsonPath('data.tags', ['wax']);
        $this->assertSame(0, (int) StockLevel::forStore($this->kids->id)->sum('on_hand'));
        $this->assertSame($robe->id, $copy->origin_product_id);
        $this->assertSame(ProductVariant::forStore($this->mode->id)->orderBy('id')->pluck('id')->all(), ProductVariant::forStore($this->kids->id)->orderBy('id')->pluck('origin_variant_id')->all());

        // Photo copiée (indépendante) et rattachée à la même variante
        $photo = ProductImage::forStore($this->kids->id)->sole();
        $this->assertNotSame(ProductImage::forStore($this->mode->id)->sole()->path, $photo->path);
        Storage::disk('public')->assertExists("{$photo->path}/medium.webp");
        $this->assertSame($photo->id, ProductVariant::forStore($this->kids->id)->where('sku', 'RW-S')->value('image_id'));

        // Déjà présente : pas de deuxième copie ; visible des deux côtés
        $this->storeApi($this->mode, 'POST', "products/{$robe->id}/copy", ['store_id' => $this->kids->id])->assertStatus(422)
            ->assertJsonPath('message', '« Robe wax » est déjà dans Awa Kids.');
        $this->storeApi($this->mode, 'GET', "products/{$robe->id}/stores")->assertJsonPath('data.0.product_id', $copy->id);
        $this->storeApi($this->kids, 'GET', "products/{$copy->id}/stores")->assertJsonPath('data.0.product_id', $robe->id);
    }

    public function test_only_towards_stores_where_one_manages_the_catalog(): void
    {
        $robe = $this->robe();
        $manager = $this->addMember($this->mode, User::factory()->create(), Role::Manager);
        $this->actingAs($manager);

        $this->storeApi($this->mode, 'GET', "products/{$robe->id}/stores")->assertJsonPath('data', []);
        $this->storeApi($this->mode, 'POST', "products/{$robe->id}/copy", ['store_id' => $this->kids->id])->assertStatus(422)
            ->assertJsonPath('message', 'Vous ne gérez pas le catalogue de cette boutique.');

        // Une boutique d'une autre adresse n'est jamais une destination
        $other = $this->createStore(['slug' => 'voisin', 'owner_id' => $this->mode->owner_id]);
        $this->actingAs($this->mode->owner);
        $this->storeApi($this->mode, 'POST', "products/{$robe->id}/copy", ['store_id' => $other->id])->assertStatus(422);
    }

    public function test_stock_is_sent_to_another_store(): void
    {
        $robe = $this->robe();
        [$small, $medium] = ProductVariant::forStore($this->mode->id)->with('product')->orderBy('id')->get()->all();

        // Pas encore dans l'autre boutique
        $this->storeApi($this->mode, 'POST', 'stock/store-transfers', ['store_id' => $this->kids->id, 'items' => [['variant_id' => $small->id, 'quantity' => 2]]])
            ->assertStatus(422)->assertJsonPath('message', "« Robe wax — S » n'existe pas dans Awa Kids : copiez d'abord le produit.");

        $this->storeApi($this->mode, 'POST', "products/{$robe->id}/copy", ['store_id' => $this->kids->id])->assertCreated();
        $this->storeApi($this->mode, 'POST', 'stock/store-transfers', ['store_id' => $this->kids->id, 'items' => [['variant_id' => $medium->id, 'quantity' => 4]]])
            ->assertStatus(422)->assertJsonPath('message', 'Stock insuffisant pour « Robe wax — M » : 3 disponible(s).');

        $this->storeApi($this->mode, 'POST', 'stock/store-transfers', [
            'store_id' => $this->kids->id, 'note' => 'Rentrée',
            'items' => [['variant_id' => $small->id, 'quantity' => 4], ['variant_id' => $medium->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('message', '5 article(s) envoyé(s) à Awa Kids.');

        $onHand = fn (Store $store) => StockLevel::forStore($store->id)->join('product_variants', 'product_variants.id', '=', 'stock_levels.variant_id')
            ->pluck('stock_levels.on_hand', 'product_variants.sku')->all();
        $this->assertEquals(['RW-S' => 6, 'RW-M' => 2], $onHand($this->mode));
        $this->assertEquals(['RW-S' => 4, 'RW-M' => 1], $onHand($this->kids));

        // Tracé des deux côtés, coût d'achat repris
        $this->assertSame([-4, -1], StockMovement::forStore($this->mode->id)->where('type', StockMovementType::TransferOut)->orderBy('id')->pluck('on_hand_change')->all());
        $in = StockMovement::forStore($this->kids->id)->where('type', StockMovementType::TransferIn)->orderBy('id')->get();
        $this->assertSame(['Depuis Awa Mode', 'Depuis Awa Mode'], $in->pluck('note')->all());
        $this->assertSame(6000, $in->first()->unit_cost);

        $this->storeApi($this->mode, 'GET', 'stock/store-transfers')->assertJsonPath('data.0.direction', 'out')
            ->assertJsonPath('data.0.quantity', 5)->assertJsonPath('stores.*.name', ['Awa Kids']);
        $this->storeApi($this->kids, 'GET', 'stock/store-transfers')->assertJsonPath('data.0.direction', 'in')->assertJsonPath('data.0.from', 'Awa Mode')
            ->assertJsonPath('data.0.lines.0.title', 'Robe wax — S');
    }

    public function test_the_same_sku_links_products_created_separately(): void
    {
        $robe = $this->robe();
        $this->storeApi($this->kids, 'POST', 'products', ['title' => 'Robe wax enfant', 'options' => [], 'variants' => [['options' => [], 'price' => 8000, 'sku' => 'RW-S']]])->assertCreated();

        $this->storeApi($this->mode, 'GET', "products/{$robe->id}/stores")->assertJsonPath('data.0.product_id', fn ($id) => $id !== null);
        $this->storeApi($this->mode, 'POST', 'stock/store-transfers', ['store_id' => $this->kids->id, 'items' => [['variant_id' => ProductVariant::forStore($this->mode->id)->where('sku', 'RW-S')->value('id'), 'quantity' => 1]]])
            ->assertCreated();
    }
}
