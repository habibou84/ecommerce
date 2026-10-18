<?php

namespace Tests\Feature\Marketing;

use App\Models\ProductImage;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Catalog\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogFeedTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->store->forceFill(['storefront_enabled' => true])->save();
        $this->actingAs($this->store->owner);
    }

    private function photo(int $productId): void
    {
        $stored = app(ImageStore::class)->store(UploadedFile::fake()->image('robe.jpg', 800, 800), $this->store->id);
        ProductImage::create(['store_id' => $this->store->id, 'product_id' => $productId, 'path' => $stored['path'], 'width' => 800, 'height' => 800, 'position' => 0]);
    }

    public function test_meta_catalog_feed(): void
    {
        $writer = app(CatalogWriter::class);
        $robe = $writer->save($this->store, null, [
            'title' => 'Robe wax', 'description' => "<p>Coupe évasée.</p>\nTissu coton.",
            'options' => [['name' => 'Taille', 'values' => ['S', 'M']], ['name' => 'Couleur', 'values' => ['Rouge']]],
            'variants' => [
                ['options' => ['S', 'Rouge'], 'sku' => 'ROBE-S', 'price' => 15000, 'compare_at_price' => 18000, 'on_hand' => 3],
                ['options' => ['M', 'Rouge'], 'price' => 15000, 'on_hand' => 0],
            ],
        ]);
        $this->photo($robe->id);
        // Sans photo : pas dans le flux (Meta le refuserait)
        $writer->save($this->store, null, ['title' => 'Sac', 'variants' => [['price' => 9000, 'on_hand' => 2]]]);

        $settings = $this->storeApi($this->store, 'GET', 'marketing/tracking')->assertOk()->assertJsonPath('data.catalog_items', 2)->json('data');
        $url = $settings['catalog_feed_url'];
        $this->assertMatchesRegularExpression('#/flux/catalogue/[A-Za-z0-9]{32}\.csv$#', $url);

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $lines = array_map('str_getcsv', array_filter(explode("\n", $response->getContent())));
        $rows = array_map(fn ($l) => array_combine($lines[0], $l), array_slice($lines, 1));
        $this->assertCount(2, $rows);
        [$s, $m] = $rows;
        $this->assertSame(['ROBE-S', 'P'.$robe->id, 'Robe wax — S / Rouge', 'in stock', '18000 XOF', '15000 XOF', 'S', 'Rouge', '3'],
            [$s['id'], $s['item_group_id'], $s['title'], $s['availability'], $s['price'], $s['sale_price'], $s['size'], $s['color'], $s['quantity_to_sell_on_facebook']]);
        $this->assertSame('Coupe évasée. Tissu coton.', $s['description']);
        $this->assertStringEndsWith('/produits/'.$robe->slug, $s['link']);
        $this->assertStringEndsWith('/large.webp', $s['image_link']);
        $this->assertSame(['V'.$robe->variants[1]->id, 'out of stock', '', 'Awa Mode'], [$m['id'], $m['availability'], $m['sale_price'], $m['brand']]);

        // Mauvais jeton ; lien renouvelé : l'ancien ne marche plus
        $this->get(preg_replace('#/[A-Za-z0-9]{32}\.csv$#', '/'.str_repeat('a', 32).'.csv', $url))->assertNotFound();
        $renewed = $this->storeApi($this->store, 'POST', 'marketing/catalog-feed/renew')->assertOk()->json('data.catalog_feed_url');
        $this->assertNotSame($url, $renewed);
        $this->get($url)->assertNotFound();
        $this->get($renewed)->assertOk();
    }
}
