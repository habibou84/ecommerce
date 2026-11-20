<?php

namespace Tests\Feature\Orders;

use App\Models\Place;
use App\Services\Places\DeliveryRates;
use App\Services\Places\Places;
use Tests\TestCase;

/**
 * Adresse de livraison : la recherche propose la ville de la boutique (communes, quartiers) et Grand-Bassam, livré
 * directement ; les villes de l'intérieur ne sortent que par l'entrée « Expédition », puis le choix de la ville.
 */
class ExpeditionPlacesTest extends TestCase
{
    public function test_inland_cities_only_come_through_the_expedition_entry(): void
    {
        $store = $this->createStore();
        app(Places::class)->provisionDefaultZone($store);
        $this->actingAs($store->owner);

        // Recherche : pas de ville de l'intérieur ni de ses quartiers
        $this->assertSame([], $this->storeApi($store, 'GET', 'orders/delivery-options/places?search=bouake')->json('data'));
        $this->assertSame([], $this->storeApi($store, 'GET', 'orders/delivery-options/places?search=commerce')->json('data'));
        $this->assertSame('Angré, Cocody', $this->storeApi($store, 'GET', 'orders/delivery-options/places?search=angre')->json('data.0.label'));
        $this->assertSame('Grand-Bassam', $this->storeApi($store, 'GET', 'orders/delivery-options/places?search=bassam')->json('data.0.label'));

        // Entrée « Expédition » : les villes de l'intérieur, sans Abidjan ni Grand-Bassam
        $cities = array_column($this->storeApi($store, 'GET', 'orders/delivery-options/cities')->assertOk()->json('data'), 'label');
        $this->assertContains('Bouaké', $cities);
        $this->assertNotContains('Grand-Bassam', $cities);
        $this->assertNotContains('Abidjan', $cities);

        // Grand-Bassam livré directement ; Bouaké expédié
        $rates = app(DeliveryRates::class);
        $this->assertFalse($rates->isExpedition($store, Place::whereNull('store_id')->where('code', 'grand-bassam-moossou')->first()));
        $this->assertFalse($rates->isExpedition($store, Place::whereNull('store_id')->where('code', 'abidjan-songon')->first()));
        $this->assertTrue($rates->isExpedition($store, Place::whereNull('store_id')->where('code', 'bouake')->first()));
        $this->assertTrue($rates->isExpedition($store, Place::whereNull('store_id')->where('code', 'bouake-commerce')->first()));
    }
}
