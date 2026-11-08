<?php

namespace Tests\Feature\Catalog;

use App\Models\BundleComponent;
use App\Models\Collection;
use App\Models\Place;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use App\Services\Promotions\Pricing;
use Tests\TestCase;

/**
 * Packs (fixe avec tailles, au choix) et vente en gros (lot seulement, minimum).
 */
class PacksWholesaleTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private Product $sac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $writer = app(CatalogWriter::class);
        $this->robe = $writer->save($this->store, null, ['title' => 'Robe wax', 'options' => [['name' => 'Taille', 'values' => ['S', 'M']]], 'variants' => [
            ['options' => ['S'], 'price' => 15000, 'on_hand' => 4], ['options' => ['M'], 'price' => 15000, 'on_hand' => 4],
        ]]);
        $this->sac = $writer->save($this->store, null, ['title' => 'Sac cabas', 'variants' => [['price' => 8000, 'on_hand' => 3]]]);
        $this->actingAs($this->store->owner);
    }

    private function level(int $variantId): array
    {
        $l = StockLevel::where('variant_id', $variantId)->sole();

        return [$l->on_hand, $l->reserved];
    }

    public function test_a_fixed_pack_lets_the_customer_choose_the_size(): void
    {
        $this->storeApi($this->store, 'POST', 'packs', ['title' => 'Pack robe + sac', 'price' => 20000, 'items' => [['product_id' => $this->sac->id]]])
            ->assertStatus(422);
        $id = $this->storeApi($this->store, 'POST', 'packs', ['title' => 'Pack robe + sac', 'price' => 20000, 'items' => [
            ['product_id' => $this->robe->id], ['product_id' => $this->sac->id],
        ]])->assertCreated()->json('data.id');

        $pack = Product::with('variants')->findOrFail($id);
        $this->assertTrue($pack->is_bundle);
        $this->assertTrue($pack->has_variants);
        $this->assertSame(['S', 'M'], $pack->variants->pluck('title')->all());
        $this->assertSame([23000, 23000], $pack->variants->pluck('compare_at_price')->map(fn ($v) => (int) $v)->all());
        $m = $pack->variants->firstWhere('title', 'M');
        $robeM = $this->robe->variants->firstWhere('title', 'M');
        $this->assertEqualsCanonicalizing([$robeM->id, $this->sac->variants[0]->id], BundleComponent::where('bundle_variant_id', $m->id)->pluck('component_variant_id')->all());

        // Commande du pack en taille M : la robe M et le sac sortent du stock
        $this->storeApi($this->store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $m->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame([4, 1], $this->level($robeM->id));
        $this->assertSame([3, 1], $this->level($this->sac->variants[0]->id));

        // Écran Packs : le pack et son contenu, puis modification du prix
        $this->storeApi($this->store, 'GET', 'packs')->assertOk()->assertJsonPath('data.packs.0.title', 'Pack robe + sac')
            ->assertJsonPath('data.packs.0.choices', true)->assertJsonPath('data.packs.0.contents', 'Robe wax + Sac cabas');
        $this->storeApi($this->store, 'GET', "packs/{$id}")->assertOk()->assertJsonPath('data.price', 20000)->assertJsonCount(2, 'data.items');
        $this->storeApi($this->store, 'PUT', "packs/{$id}", ['title' => 'Pack robe + sac', 'price' => 19000, 'items' => [
            ['product_id' => $this->robe->id], ['product_id' => $this->sac->id],
        ]])->assertOk();
        $this->assertSame([19000, 19000], $pack->variants()->pluck('price')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2, $pack->variants()->count());
    }

    public function test_a_pack_of_choice_prices_the_most_expensive_pieces(): void
    {
        $writer = app(CatalogWriter::class);
        $bazin = $writer->save($this->store, null, ['title' => 'Robe bazin', 'variants' => [['price' => 12000, 'on_hand' => 5]]]);
        $pagne = $writer->save($this->store, null, ['title' => 'Robe pagne', 'variants' => [['price' => 9000, 'on_hand' => 5]]]);
        $robes = Collection::create(['store_id' => $this->store->id, 'title' => 'Robes', 'slug' => 'robes']);
        $robes->products()->attach([$this->robe->id => ['position' => 1], $bazin->id => ['position' => 2], $pagne->id => ['position' => 3]]);

        $this->storeApi($this->store, 'POST', 'pack-offers', ['title' => 'Pack 3 robes', 'quantity' => 3, 'price' => 30000])->assertStatus(422);
        $this->storeApi($this->store, 'POST', 'pack-offers', ['title' => 'Pack 3 robes', 'quantity' => 3, 'price' => 30000, 'collection_id' => $robes->id])
            ->assertCreated()->assertJsonPath('data.collection', 'Robes');

        $pricing = app(Pricing::class);
        $line = fn (Product $p, int $q) => ['variant' => $p->variants()->first(), 'quantity' => $q];
        // 15 000 + 12 000 + 9 000 = 36 000 → 30 000
        $prices = $pricing->lines($this->store->id, [$line($this->robe, 1), $line($bazin, 1), $line($pagne, 1)]);
        $this->assertSame(30000, array_sum(array_column($prices, 'total')));
        $this->assertTrue($prices[0]['volume']);
        // 4 pièces : les 3 plus chères dans le pack, la robe pagne au prix normal
        $prices = $pricing->lines($this->store->id, [$line($this->robe, 2), $line($bazin, 1), $line($pagne, 1)]);
        $this->assertSame(39000, array_sum(array_column($prices, 'total')));
        $this->assertSame(9000, $prices[2]['total']);
        // 2 pièces seulement : pas de pack ; le sac n'est pas dans la catégorie
        $prices = $pricing->lines($this->store->id, [$line($this->robe, 1), $line($this->sac, 2)]);
        $this->assertSame(31000, array_sum(array_column($prices, 'total')));
        $this->assertSame([['title' => 'Pack 3 robes', 'quantity' => 3, 'price' => 30000]], $pricing->packsFor($this->store->id, $bazin->id));
    }

    public function test_wholesale_lot_and_minimum(): void
    {
        $this->storeApi($this->store, 'PUT', "products/{$this->sac->id}/volume-prices", [
            'tiers' => [['min_quantity' => 6, 'price' => 42000]], 'sale_lot' => 6, 'min_order_quantity' => null,
        ])->assertOk()->assertJsonPath('sale.sale_lot', 6);
        $sac = $this->sac->fresh();
        $this->assertSame(6, $sac->sellableQuantity(1));
        $this->assertSame(12, $sac->sellableQuantity(7));
        $this->assertSame('par lot de 6', $sac->lotLabel());

        $robe = $this->robe->fresh();
        $robe->forceFill(['min_order_quantity' => 3])->save();
        $this->assertSame(3, $robe->sellableQuantity(1));
        $this->assertSame(5, $robe->sellableQuantity(5));
        $this->assertSame('3 pièces minimum', $robe->lotLabel());
    }
}
