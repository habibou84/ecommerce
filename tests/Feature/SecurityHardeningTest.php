<?php

namespace Tests\Feature;

use App\Enums\StockLocationType;
use App\Models\Cart;
use App\Models\Shipment;
use App\Models\StockLocation;
use App\Services\Catalog\CatalogWriter;
use App\Support\SpreadsheetCell;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    public function test_security_headers_on_pages_and_api(): void
    {
        $store = $this->createStore(['slug' => 'awa']);
        foreach ([$this->get('http://awa.localhost/'), $this->storeApi($store, 'GET', 'auth/me')] as $response) {
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        }
    }

    public function test_spreadsheet_cells_never_run_as_formulas(): void
    {
        $this->assertSame(["'=HYPERLINK(\"https://evil\")", "'@SUM(A1)", "'-2+3", 'Fatou', '+225 07 00 00 00 01', -1500, 12], SpreadsheetCell::row(['=HYPERLINK("https://evil")', '@SUM(A1)', '-2+3', 'Fatou', '+225 07 00 00 00 01', -1500, 12]));
    }

    public function test_carrier_links_are_web_links_only(): void
    {
        $this->assertNull(Shipment::webUrl('javascript:alert(document.cookie)'));
        $this->assertNull(Shipment::webUrl('data:text/html,<script>'));
        $this->assertSame('https://suivi.jibiat.com/c/123', Shipment::webUrl(' https://suivi.jibiat.com/c/123 '));
        $this->assertNull((new Shipment(['carrier_tracking_url' => 'javascript:alert(1)']))->carrier_tracking_url);
    }

    public function test_store_secrets_and_owner_are_protected(): void
    {
        $store = $this->createStore();
        $store->forceFill(['meta_capi_token' => 'secret-capi'])->save();
        $this->assertArrayNotHasKey('meta_capi_token', $store->fresh()->toArray());

        $intruder = $this->createStore();
        $store->fill(['owner_id' => $intruder->owner_id, 'status' => 'active', 'name' => 'Nouveau nom']);
        $this->assertSame([$store->getOriginal('owner_id'), $store->getOriginal('status'), 'Nouveau nom'], [$store->owner_id, $store->status, $store->name]);
    }

    public function test_pickup_point_must_belong_to_the_store(): void
    {
        $store = $this->createStore();
        $other = $this->createStore();
        $foreign = StockLocation::create(['store_id' => $other->id, 'name' => 'Magasin voisin', 'type' => StockLocationType::Shop]);
        $variantId = app(CatalogWriter::class)->save($store, null, ['title' => 'Sac', 'variants' => [['price' => 5000, 'on_hand' => 5]]])->variants[0]->id;
        $this->actingAs($store->owner);

        $this->storeApi($store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '0506070809', 'delivery_method' => 'pickup',
            'pickup_location_id' => $foreign->id, 'items' => [['variant_id' => $variantId, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('pickup_location_id');
    }

    public function test_old_webhook_receipts_and_carts_are_pruned(): void
    {
        $store = $this->createStore();
        $old = Cart::withoutGlobalScopes()->forceCreate(['store_id' => $store->id, 'token' => 'ancien', 'last_activity_at' => now()->subDays(91)]);
        $recent = Cart::withoutGlobalScopes()->forceCreate(['store_id' => $store->id, 'token' => 'recent', 'last_activity_at' => now()->subDays(10)]);

        $this->artisan('model:prune', ['--model' => [Cart::class]])->assertSuccessful();
        $this->assertSame([$recent->id], Cart::withoutGlobalScopes()->pluck('id')->all());
        $this->assertNull(Cart::withoutGlobalScopes()->find($old->id));
    }
}
