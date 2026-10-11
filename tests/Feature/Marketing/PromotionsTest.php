<?php

namespace Tests\Feature\Marketing;

use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Storefront\CartService;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PromotionsTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private ?string $cart = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->store->forceFill(['storefront_enabled' => true])->save();
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 10000, 'on_hand' => 50]]]);
        $this->actingAs($this->store->owner);
    }

    private function shop(string $method, string $path, array $data = []): TestResponse
    {
        $request = $this->withCredentials()->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);
        if ($this->cart) {
            $request = $request->withCookie(CartService::COOKIE, $this->cart);
        }
        $response = $request->json($method, $this->storeUrl($this->store, $path), $data);
        $this->cart = $response->getCookie(CartService::COOKIE)?->getValue() ?? $this->cart;

        return $response;
    }

    private function setQuantity(int $quantity): TestResponse
    {
        return $this->shop('POST', '/panier/articles', ['variant_id' => $this->robe->variants[0]->id, 'quantity' => $quantity, 'replace' => true])->assertOk();
    }

    private function checkout(string $phone = '0506070809'): TestResponse
    {
        return $this->shop('POST', '/commande', [
            'name' => 'Fatou Diallo', 'phone' => $phone, 'delivery_method' => 'delivery',
            'place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
        ]);
    }

    private function flash(int $percent, array $extra = []): Discount
    {
        $id = $this->storeApi($this->store, 'POST', 'promotions', [
            'title' => 'Tabaski', 'type' => 'percentage', 'value' => $percent, 'applies_to' => 'products', 'target_ids' => [$this->robe->id],
            'ends_at' => now()->addDays(2)->toIso8601String(), ...$extra,
        ])->assertCreated()->json('data.id');

        return Discount::findOrFail($id);
    }

    private function code(array $data): Discount
    {
        $id = $this->storeApi($this->store, 'POST', 'promotions', ['applies_to' => 'all', ...$data])->assertCreated()->json('data.id');

        return Discount::findOrFail($id);
    }

    public function test_flash_sale_and_volume_price_take_the_best_for_the_customer(): void
    {
        $this->storeApi($this->store, 'PUT', "products/{$this->robe->id}/volume-prices", ['tiers' => [['min_quantity' => 3, 'price' => 25000]]])
            ->assertOk()->assertJsonPath('data.0.price', 25000);
        $this->storeApi($this->store, 'PUT', "products/{$this->robe->id}/volume-prices", ['tiers' => [['min_quantity' => 2, 'price' => 30000]]])
            ->assertStatus(422)->assertJsonValidationErrors('tiers.0.price');
        $this->flash(10);

        // 2 robes : vente flash (9 000 F au lieu de 10 000 F)
        $this->setQuantity(2)->assertJsonPath('data.lines.0.unit_price', 9000)->assertJsonPath('data.lines.0.compare_at', 10000)
            ->assertJsonPath('data.subtotal', 18000);
        // 3 robes : le lot à 25 000 F est plus avantageux que 3 × 9 000 F
        $this->setQuantity(3)->assertJsonPath('data.lines.0.total', 25000)->assertJsonPath('data.lines.0.volume', true);

        $page = $this->get($this->storeUrl($this->store, '/produits/'.$this->robe->slug))->assertOk();
        $page->assertSee('Vente flash')->assertSee('data-countdown', false)->assertSee('Prix par quantité');
        $this->get($this->storeUrl($this->store, '/produits'))->assertOk()->assertSee('Vente flash')->assertSee('9 000 F');

        $this->checkout()->assertCreated();
        $order = Order::latest('id')->first();
        $this->assertSame([25000, 8333, null], [$order->subtotal, $order->items[0]->unit_price, $order->items[0]->discount_id]);

        // Commande saisie à la main : mêmes prix proposés par le formulaire
        $quote = $this->storeApi($this->store, 'POST', 'orders/pricing', ['items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]]])->assertOk();
        $quote->assertJsonPath('data.lines.0.unit_price', 9000)->assertJsonPath('data.lines.0.flash', true);
        $manual = app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_phone' => '+2250102030405', 'delivery_method' => 'pickup',
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1, 'unit_price' => 9000]],
        ], $this->store->owner);
        $this->assertSame([9000, Discount::first()->id], [$manual->items[0]->unit_price, $manual->items[0]->discount_id]);
        $this->assertDatabaseHas('discount_usages', ['order_id' => $manual->id, 'amount' => 1000]);
    }

    public function test_promo_code_on_the_storefront_with_limits(): void
    {
        $code = $this->code(['code' => 'bienvenue', 'type' => 'percentage', 'value' => 10, 'min_subtotal' => 20000, 'per_customer_limit' => 1]);
        $this->assertSame('BIENVENUE', $code->code);

        $this->setQuantity(1);
        $this->shop('POST', '/panier/code', ['code' => 'INCONNU'])->assertStatus(422)->assertJsonPath('errors.code.0', 'Ce code promo n\'existe pas.');
        $this->shop('POST', '/panier/code', ['code' => 'bienvenue'])->assertStatus(422)->assertJsonPath('errors.code.0', 'Ce code promo s\'applique dès 20 000 F d\'achat.');

        $this->setQuantity(3);
        $this->shop('POST', '/panier/code', ['code' => 'Bienvenue'])->assertOk()
            ->assertJsonPath('data.discount.amount', 3000)->assertJsonPath('data.total', 27000);
        $this->get($this->storeUrl($this->store, '/panier'))->assertSee('BIENVENUE')->assertSee('27 000 F');
        $quote = $this->shop('POST', '/commande/devis', ['delivery_method' => 'pickup'])->assertOk()->assertJsonPath('data.total', 27000);

        $this->checkout()->assertCreated();
        $order = Order::latest('id')->first();
        $this->assertSame(['BIENVENUE', 3000, 30000 - 3000 + $order->shipping_fee], [$order->discount_code, $order->discount_total, $order->total]);
        $this->assertSame(3000, (int) $order->items->sum('discount_amount'));
        $this->assertSame(1, $code->fresh()->used_count);

        // Même client : une seule utilisation ; le code est retiré du panier et le client prévenu
        $this->cart = null;
        $this->setQuantity(3);
        $this->shop('POST', '/panier/code', ['code' => 'BIENVENUE'])->assertOk();
        $this->checkout()->assertStatus(422)->assertJsonPath('errors.code.0', 'Vous avez déjà utilisé ce code promo. Il a été retiré : vérifiez le total avant de confirmer.');
    }

    public function test_cancelled_order_releases_the_code(): void
    {
        $code = $this->code(['code' => 'UNIQUE', 'type' => 'fixed_amount', 'value' => 2000, 'usage_limit' => 1]);
        $desk = app(OrderDesk::class);
        $data = ['source' => 'whatsapp', 'customer_phone' => '+2250102030405', 'delivery_method' => 'pickup', 'discount_code' => 'unique',
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]]];

        $order = $desk->create($this->store, $data, $this->store->owner);
        $this->assertSame([2000, 8000, 1], [$order->discount_total, $order->total, $code->fresh()->used_count]);

        // Limite atteinte pour un autre client
        $this->storeApi($this->store, 'POST', 'orders', [...$data, 'customer_phone' => '0708091011'])
            ->assertStatus(422)->assertJsonPath('errors.discount_code.0', 'Ce code promo a atteint sa limite d\'utilisation.');

        // Modifier la commande garde son code ; le retirer remet la remise à zéro
        $this->storeApi($this->store, 'PUT', "orders/{$order->id}", ['items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 2]]])
            ->assertOk()->assertJsonPath('data.discount_total', 2000)->assertJsonPath('data.total', 18000);

        $desk->transition($order->fresh(), OrderStatus::Cancelled, null, ['reason' => CancelReason::CustomerRequest]);
        $this->assertSame(0, $code->fresh()->used_count);
        $other = $desk->create($this->store, [...$data, 'customer_phone' => '+2250708091011'], $this->store->owner);
        $this->assertSame(2000, $other->discount_total);

        $this->storeApi($this->store, 'PUT', "orders/{$other->id}", ['discount_code' => null])->assertOk()
            ->assertJsonPath('data.discount_total', 0)->assertJsonPath('data.discount_code', null);
        $this->assertSame(0, $code->fresh()->used_count);
    }

    public function test_free_shipping_threshold_and_free_shipping_code(): void
    {
        $this->storeApi($this->store, 'PATCH', 'promotions/settings', ['free_shipping_above' => 30000])->assertOk();
        $placeId = Place::whereNull('store_id')->where('name', 'Angré')->value('id');

        $this->setQuantity(2)->assertJsonPath('data.free_shipping.remaining', 10000);
        $fee = $this->shop('POST', '/commande/devis', ['delivery_method' => 'delivery', 'place_id' => $placeId])->json('data.fee');
        $this->assertGreaterThan(0, $fee);

        $this->setQuantity(3)->assertJsonPath('data.free_shipping.remaining', 0);
        $this->shop('POST', '/commande/devis', ['delivery_method' => 'delivery', 'place_id' => $placeId])->assertJsonPath('data.fee', 0);

        $this->code(['code' => 'LIVRAISON', 'type' => 'free_shipping']);
        $this->setQuantity(1);
        $this->shop('POST', '/panier/code', ['code' => 'livraison'])->assertOk()->assertJsonPath('data.discount.free_shipping', true);
        $this->shop('POST', '/commande/devis', ['delivery_method' => 'delivery', 'place_id' => $placeId])->assertJsonPath('data.fee', 0)->assertJsonPath('data.total', 10000);
        $this->checkout()->assertCreated();
        $this->assertSame([0, 10000], [Order::latest('id')->first()->shipping_fee, Order::latest('id')->first()->total]);
    }

    public function test_promotions_management(): void
    {
        $this->storeApi($this->store, 'POST', 'promotions', ['title' => 'Flash', 'type' => 'percentage', 'value' => 10, 'applies_to' => 'all'])
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $this->storeApi($this->store, 'POST', 'promotions', ['code' => 'X1', 'type' => 'percentage', 'value' => 120, 'applies_to' => 'all'])
            ->assertStatus(422)->assertJsonValidationErrors(['code', 'value']);
        $this->storeApi($this->store, 'POST', 'promotions', ['title' => 'Flash', 'type' => 'free_shipping', 'applies_to' => 'all', 'ends_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('type');

        $code = $this->code(['code' => 'NOEL', 'type' => 'fixed_amount', 'value' => 1000]);
        $this->storeApi($this->store, 'POST', 'promotions', ['code' => 'noel', 'type' => 'fixed_amount', 'value' => 1000, 'applies_to' => 'all'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Ce code existe déjà.');
        $flash = $this->flash(20, ['starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String()]);

        app(OrderDesk::class)->create($this->store, ['source' => 'whatsapp', 'customer_phone' => '+2250102030405', 'delivery_method' => 'pickup', 'discount_code' => 'NOEL',
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]]], $this->store->owner);

        $list = $this->storeApi($this->store, 'GET', 'promotions')->assertOk();
        $rows = collect($list->json('data'))->keyBy('id');
        $this->assertSame(['flash', 'scheduled', ['Robe wax']], [$rows[$flash->id]['kind'], $rows[$flash->id]['status'], $rows[$flash->id]['targets']]);
        $this->assertSame(['running', 1, 1000, 9000], [$rows[$code->id]['status'], $rows[$code->id]['orders'], $rows[$code->id]['discounted'], $rows[$code->id]['revenue']]);

        $this->storeApi($this->store, 'DELETE', "promotions/{$code->id}")->assertOk();
        $this->assertFalse($code->fresh()->is_active);
        $this->storeApi($this->store, 'DELETE', "promotions/{$flash->id}")->assertOk();
        $this->assertNull(Discount::find($flash->id));
    }
}
