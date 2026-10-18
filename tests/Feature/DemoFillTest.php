<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\Product;
use Tests\TestCase;

class DemoFillTest extends TestCase
{
    public function test_a_preprod_store_is_filled_with_fictitious_data(): void
    {
        $store = $this->createStore(['slug' => 'boldstyle', 'name' => 'Bold Style'], 'free');

        $this->artisan('demo:fill', ['store' => 'Bold Style', '--orders' => 30])->assertSuccessful();

        $this->assertSame(11, Product::forStore($store->id)->count());
        $this->assertGreaterThanOrEqual(25, Order::forStore($store->id)->count());
        $this->assertTrue(Order::forStore($store->id)->where('status', 'completed')->exists());
        $this->assertTrue(Order::forStore($store->id)->where('status', 'confirmed')->exists());
        $this->assertSame(0, Customer::forStore($store->id)->where('phone', 'not like', '+22507990000%')->count());
        $this->assertSame(0, OutboundMessage::count());
        $this->assertTrue(Product::forStore($store->id)->where('is_bundle', true)->exists());

        // Relancer n'ajoute pas un second catalogue
        $this->artisan('demo:fill', ['store' => 'boldstyle', '--orders' => 2])->assertSuccessful();
        $this->assertSame(11, Product::forStore($store->id)->count());
        $this->artisan('demo:fill', ['store' => 'inconnue'])->assertFailed();
    }
}
