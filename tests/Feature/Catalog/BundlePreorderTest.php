<?php

namespace Tests\Feature\Catalog;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BundlePreorderTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->store->forceFill(['storefront_enabled' => true])->save();
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
    }

    private function order(Product $product, int $quantity): TestResponse
    {
        return $this->storeApi($this->store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $product->variants[0]->id, 'quantity' => $quantity]],
        ]);
    }

    private function level(Product $product): array
    {
        $l = StockLevel::where('variant_id', $product->variants[0]->id)->sole();

        return [$l->on_hand, $l->reserved];
    }

    private function sellable(Product $product): ?int
    {
        $catalog = app(StorefrontCatalog::class);
        $variant = $product->fresh()->variants[0];

        return $catalog->sellable($variant, $catalog->availability($this->store, [$variant->id]));
    }

    public function test_a_bundle_moves_the_stock_of_its_items(): void
    {
        $writer = app(CatalogWriter::class);
        $robe = $writer->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'cost_price' => 5000, 'on_hand' => 5]]]);
        $sac = $writer->save($this->store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 9000, 'cost_price' => 3000, 'on_hand' => 2]]]);
        $pack = $writer->save($this->store, null, ['title' => 'Pack robe + sac', 'variants' => [['price' => 21000]]]);

        $this->storeApi($this->store, 'PUT', "products/{$pack->id}/bundle", ['components' => [['variant_id' => $pack->variants[0]->id, 'quantity' => 1]]])->assertStatus(422);
        $this->storeApi($this->store, 'PUT', "products/{$pack->id}/bundle", ['components' => [
            ['variant_id' => $robe->variants[0]->id, 'quantity' => 1], ['variant_id' => $sac->variants[0]->id, 'quantity' => 1],
        ]])->assertOk()->assertJsonPath('data.is_bundle', true)->assertJsonPath('data.components.1.title', 'Sac en pagne');
        $this->assertFalse($pack->fresh()->track_inventory);
        // Disponible autant de fois que l'article le plus juste (2 sacs)
        $this->assertSame(2, $this->sellable($pack));

        $this->order($pack, 3)->assertStatus(422);
        $id = $this->order($pack, 2)->assertCreated()->json('data.id');
        $this->assertSame([[5, 2], [2, 2]], [$this->level($robe), $this->level($sac)]);
        $this->assertSame(0, $this->sellable($pack));
        $order = Order::with('items')->findOrFail($id);
        $this->assertSame(8000, $order->items[0]->unit_cost);

        $desk = app(OrderDesk::class);
        $desk->transition($desk->transition($order, OrderStatus::Shipped, $this->store->owner), OrderStatus::Completed, $this->store->owner);
        $this->assertSame([[3, 0], [0, 0]], [$this->level($robe), $this->level($sac)]);

        // Un pack rendu : chaque article revient en stock
        $return = $this->storeApi($this->store, 'POST', "orders/{$id}/returns", ['items' => [['order_item_id' => $order->items[0]->id, 'quantity' => 1]]])->assertCreated()->json('data.id');
        $this->storeApi($this->store, 'POST', "returns/{$return}/receive", ['refund' => false])->assertOk();
        $this->assertSame([[4, 0], [1, 0]], [$this->level($robe), $this->level($sac)]);

        // Commande annulée : les articles réservés sont libérés
        $cancelled = Order::findOrFail($this->order($pack, 1)->assertCreated()->json('data.id'));
        $this->assertSame([4, 1], $this->level($robe));
        $desk->transition($cancelled, OrderStatus::Cancelled, $this->store->owner);
        $this->assertSame([[4, 0], [1, 0]], [$this->level($robe), $this->level($sac)]);

        // Fiche de la boutique : contenu du lot
        $this->get($this->store->url('/produits/'.$pack->slug))->assertOk()->assertSee('Ce lot contient')->assertSee('1 × Sac en pagne')->assertSee('Vous économisez');
    }

    public function test_preorder_with_deposit(): void
    {
        $robe = app(CatalogWriter::class)->save($this->store, null, [
            'title' => 'Robe Tabaski', 'preorder_enabled' => true, 'preorder_ship_date' => now()->addDays(20)->toDateString(), 'preorder_deposit_percent' => 30,
            'variants' => [['price' => 20000, 'on_hand' => 0]],
        ]);
        $this->assertNull($this->sellable($robe));

        $id = $this->order($robe, 2)->assertCreated()->json('data.id');
        $order = Order::findOrFail($id);
        $this->assertTrue($order->is_preorder);
        $this->assertSame(12000, $order->deposit_required);
        $this->assertSame([0, 2], $this->level($robe));
        $this->storeApi($this->store, 'GET', "orders/{$id}")->assertJsonPath('data.is_preorder', true);

        $this->get($this->store->url('/produits/'.$robe->slug))->assertOk()->assertSee('Précommander')->assertSee('acompte de 30 %', false);
    }
}
