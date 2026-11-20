<?php

namespace Tests\Feature\Orders;

use App\Models\Place;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Tests\TestCase;

/**
 * Champ « Où livrer » du formulaire de commande : un seul champ pour les communes et les quartiers ; sans recherche,
 * les lieux les plus livrés ; avec une recherche, ceux-là passent en tête.
 */
class DeliveryPlaceSearchTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
    }

    private function orderTo(string $place): void
    {
        $variant = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe '.uniqid(), 'variants' => [['price' => 10000, 'on_hand' => 5]]])->variants[0];
        app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_phone' => '+22505'.random_int(10000000, 99999999), 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', $place)->value('id'),
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ], $this->store->owner);
    }

    public function test_most_delivered_places_come_first(): void
    {
        // Rien encore livré, rien tapé : la liste est vide
        $this->storeApi($this->store, 'GET', 'orders/delivery-options/places')->assertOk()->assertJsonPath('data', []);

        $this->orderTo('Angré');
        $this->orderTo('Angré');
        $this->orderTo('Yopougon');

        // Champ touché sans rien taper : les plus livrés d'abord, avec leur nombre de commandes
        $top = $this->storeApi($this->store, 'GET', 'orders/delivery-options/places')->assertOk()->json('data');
        $this->assertSame(['Angré', 'Yopougon'], array_map(fn ($p) => explode(',', $p['label'])[0], $top));
        $this->assertSame([true, 2], [$top[0]['frequent'], $top[0]['orders_count']]);

        // Recherche : le lieu habituel est marqué ; les autres résultats suivent
        $found = $this->storeApi($this->store, 'GET', 'orders/delivery-options/places?search=angre')->assertOk()->json('data');
        $this->assertStringStartsWith('Angré', $found[0]['label']);
        $this->assertTrue($found[0]['frequent']);
        $this->assertTrue(collect($found)->slice(1)->every(fn ($p) => ! $p['frequent'] || $p['orders_count'] <= 2));
    }
}
