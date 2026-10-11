<?php

namespace Tests\Feature\Catalog;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Collection;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Catalog\CatalogWriter;
use App\Services\Stock\StockKeeper;
use Tests\TestCase;

class CatalogWriterTest extends TestCase
{
    private function writer(): CatalogWriter
    {
        return app(CatalogWriter::class);
    }

    private function tshirt(): array
    {
        return [
            'title' => 'T-shirt Basic',
            'options' => [
                ['name' => 'Couleur', 'values' => ['Rouge', 'Bleu']],
                ['name' => 'Taille', 'values' => ['M', 'L']],
            ],
            'variants' => [
                ['options' => ['Rouge', 'M'], 'price' => 5000, 'sku' => 'TS-R-M', 'on_hand' => 4],
                ['options' => ['Rouge', 'L'], 'price' => 5000, 'sku' => 'TS-R-L'],
                ['options' => ['Bleu', 'M'], 'price' => 5500, 'sku' => 'TS-B-M', 'on_hand' => 2],
            ],
        ];
    }

    public function test_a_simple_product_has_one_default_variant(): void
    {
        $store = $this->createStore();

        $product = $this->writer()->save($store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 12000, 'on_hand' => 3]]]);

        $this->assertSame('sac-en-pagne', $product->slug);
        $this->assertFalse($product->has_variants);
        $this->assertCount(1, $product->variants);
        $this->assertTrue($product->variants[0]->is_default);
        $this->assertSame('Par défaut', $product->variants[0]->title);
        $this->assertSame(12000, $product->price_min);
        $this->assertSame(3, StockLevel::where('variant_id', $product->variants[0]->id)->value('on_hand'));
    }

    public function test_variants_combine_option_values(): void
    {
        $store = $this->createStore();

        $product = $this->writer()->save($store, null, $this->tshirt());

        $this->assertTrue($product->has_variants);
        $this->assertSame(['Couleur', 'Taille'], $product->options->pluck('name')->all());
        $this->assertSame(['Rouge / M', 'Rouge / L', 'Bleu / M'], $product->variants->pluck('title')->all());
        $this->assertSame([5000, 5500], [$product->price_min, $product->price_max]);
        $this->assertSame('T-shirt Basic — Bleu / M', $product->variants[2]->fullTitle());

        $stock = StockLevel::whereIn('variant_id', $product->variants->pluck('id'))->pluck('on_hand', 'variant_id');
        $this->assertSame(4, $stock[$product->variants[0]->id]);
        $this->assertSame(StockMovementType::Adjustment, StockMovement::where('variant_id', $product->variants[0]->id)->first()->type);
    }

    public function test_updating_keeps_variant_ids_and_deletes_removed_variants(): void
    {
        $store = $this->createStore();
        $product = $this->writer()->save($store, null, $this->tshirt());
        [$redM, $redL, $blueM] = $product->variants;

        $data = $this->tshirt();
        $data['options'][1]['values'] = ['M'];
        $data['variants'] = [
            ['id' => $redM->id, 'options' => ['Rouge', 'M'], 'price' => 6000, 'sku' => 'TS-R-M'],
            ['id' => $blueM->id, 'options' => ['Bleu', 'M'], 'price' => 6000, 'sku' => 'TS-B-M'],
        ];
        $updated = $this->writer()->save($store, $product, $data);

        $this->assertSame([$redM->id, $blueM->id], $updated->variants->pluck('id')->all());
        $this->assertSame(6000, $updated->price_min);
        $this->assertSoftDeleted('product_variants', ['id' => $redL->id]);
        $this->assertSame(['M'], $updated->options[1]->values->pluck('value')->all());
        // Le stock de la variante gardée ne bouge pas
        $this->assertSame(4, StockLevel::where('variant_id', $redM->id)->value('on_hand'));
    }

    public function test_a_deleted_variant_leaves_the_stock(): void
    {
        $store = $this->createStore();
        $product = $this->writer()->save($store, null, $this->tshirt());
        $blueM = $product->variants[2];

        $data = $this->tshirt();
        array_pop($data['variants']);
        $this->writer()->save($store, $product, $data);

        $this->assertSame(0, StockLevel::where('variant_id', $blueM->id)->value('on_hand'));
        $this->assertSame('Variante supprimée', StockMovement::where('variant_id', $blueM->id)->latest('id')->value('note'));
    }

    public function test_a_reserved_variant_cannot_be_deleted(): void
    {
        $store = $this->createStore();
        $product = $this->writer()->save($store, null, $this->tshirt());
        $redM = $product->variants[0];
        app(StockKeeper::class)->reserve(null, $redM, app(StockKeeper::class)->defaultLocation($store), 1, $product);

        $data = $this->tshirt();
        array_shift($data['variants']);

        $this->expectException(BusinessRuleException::class);
        $this->writer()->save($store, $product, $data);
    }

    public function test_switching_between_simple_and_variants(): void
    {
        $store = $this->createStore();
        $product = $this->writer()->save($store, null, ['title' => 'Robe', 'variants' => [['price' => 15000]]]);
        $default = $product->variants[0];

        $product = $this->writer()->save($store, $product, [
            'title' => 'Robe',
            'options' => [['name' => 'Taille', 'values' => ['S', 'M']]],
            'variants' => [['options' => ['S'], 'price' => 15000], ['options' => ['M'], 'price' => 16000]],
        ]);
        $this->assertSame(['S', 'M'], $product->variants->pluck('title')->all());
        $this->assertSoftDeleted('product_variants', ['id' => $default->id]);

        $product = $this->writer()->save($store, $product, ['title' => 'Robe', 'options' => [], 'variants' => [['id' => $product->variants[0]->id, 'price' => 15000]]]);
        $this->assertCount(1, $product->variants);
        $this->assertTrue($product->variants[0]->is_default);
        $this->assertCount(0, $product->options);
    }

    public function test_invalid_variants_are_refused(): void
    {
        $store = $this->createStore();

        $duplicate = $this->tshirt();
        $duplicate['variants'][1]['options'] = ['Rouge', 'M'];
        $this->assertThrowsRule(fn () => $this->writer()->save($store, null, $duplicate), 'même combinaison');

        $unknown = $this->tshirt();
        $unknown['variants'][0]['options'] = ['Vert', 'M'];
        $this->assertThrowsRule(fn () => $this->writer()->save($store, null, $unknown), 'Couleur');

        $tooMany = ['title' => 'X', 'options' => array_fill(0, 4, ['name' => 'O', 'values' => ['a']]), 'variants' => []];
        $tooMany['options'] = array_map(fn ($i) => ['name' => "O{$i}", 'values' => ['a']], range(1, 4));
        $this->assertThrowsRule(fn () => $this->writer()->save($store, null, $tooMany), 'Trois options');
    }

    public function test_skus_are_unique_per_store_and_slugs_are_deduplicated(): void
    {
        $store = $this->createStore();
        $other = $this->createStore();
        $this->writer()->save($store, null, $this->tshirt());

        $this->assertThrowsRule(fn () => $this->writer()->save($store, null, ['title' => 'Autre', 'variants' => [['price' => 1, 'sku' => 'TS-R-M']]]), 'TS-R-M');
        // Même SKU dans une autre boutique : permis
        $this->writer()->save($other, null, ['title' => 'Autre', 'variants' => [['price' => 1, 'sku' => 'TS-R-M']]]);

        $second = $this->writer()->save($store, null, ['title' => 'T-shirt Basic', 'variants' => [['price' => 1]]]);
        $this->assertSame('t-shirt-basic-2', $second->slug);
    }

    public function test_tags_and_collections_are_synced(): void
    {
        $store = $this->createStore();
        $collection = Collection::create(['store_id' => $store->id, 'title' => 'Été', 'slug' => 'ete']);

        $product = $this->writer()->save($store, null, ['title' => 'Robe', 'variants' => [['price' => 1]], 'tags' => ['Promo', 'promo', 'Nouveau'], 'collection_ids' => [$collection->id]]);

        $this->assertSame(['Promo', 'Nouveau'], $product->tags->pluck('name')->all());
        $this->assertSame([$collection->id], $product->collections->pluck('id')->all());
    }

    public function test_the_plan_limits_the_number_of_products(): void
    {
        $store = $this->createStore(plan: null);
        Plan::where('code', 'free')->update(['limits' => json_encode(['products' => 1])]);

        $this->writer()->save($store, null, ['title' => 'A', 'variants' => [['price' => 1]]]);
        $this->assertThrowsRule(fn () => $this->writer()->save($store, null, ['title' => 'B', 'variants' => [['price' => 1]]]), 'limitée à 1 produits');
    }

    public function test_deleting_a_product_clears_its_stock(): void
    {
        $store = $this->createStore();
        $product = $this->writer()->save($store, null, $this->tshirt());

        $this->writer()->delete($product);

        $this->assertSoftDeleted($product);
        $this->assertSame(0, (int) StockLevel::whereIn('variant_id', $product->variants->pluck('id'))->sum('on_hand'));
        $this->assertSame(0, ProductVariant::forStore($store->id)->count());
        $this->assertSame(0, Product::forStore($store->id)->count());
    }

    private function assertThrowsRule(callable $callback, string $contains): void
    {
        try {
            $callback();
            $this->fail('Exception attendue : '.$contains);
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }
}
