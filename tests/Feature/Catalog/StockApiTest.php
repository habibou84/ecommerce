<?php

namespace Tests\Feature\Catalog;

use App\Enums\Role;
use App\Models\Store;
use Tests\TestCase;

class StockApiTest extends TestCase
{
    private Store $store;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore();
        $this->actingAs($this->store->owner);

        $this->variantId = $this->api('POST', 'products', [
            'title' => 'Sac', 'options' => [], 'variants' => [['price' => 9000, 'sku' => 'SAC', 'on_hand' => 10]],
        ])->json('data.variants.0.id');
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->storeApi($this->store, $method, $path, $data);
    }

    private function locations(): array
    {
        return $this->api('GET', 'stock/locations')->json('data');
    }

    public function test_locations_are_managed_and_cannot_be_disabled_with_stock(): void
    {
        $main = $this->locations()[0];
        $this->assertSame(['Stock principal', 10], [$main['name'], $main['units']]);

        $warehouse = $this->api('POST', 'stock/locations', ['name' => 'Entrepôt', 'type' => 'warehouse'])->assertCreated()->json('data');
        $this->api('POST', 'stock/locations', ['name' => 'X', 'type' => 'carrier_hub'])->assertStatus(422);

        $this->api('PUT', "stock/locations/{$main['id']}", ['name' => 'Boutique', 'type' => 'shop', 'is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('is_active');
        $this->api('PUT', "stock/locations/{$warehouse['id']}", ['name' => 'Entrepôt', 'type' => 'warehouse', 'is_active' => false])->assertOk();
    }

    public function test_adjustments_levels_and_journal(): void
    {
        $location = $this->locations()[0]['id'];

        $this->api('POST', 'stock/adjustments', ['variant_id' => $this->variantId, 'location_id' => $location, 'quantity' => -2, 'reason' => 'damaged', 'note' => 'Taché'])
            ->assertOk()->assertJsonPath('data.on_hand', 8);
        $this->api('POST', 'stock/adjustments', ['variant_id' => $this->variantId, 'location_id' => $location, 'on_hand' => 12])
            ->assertOk()->assertJsonPath('data.on_hand', 12);
        $this->api('POST', 'stock/adjustments', ['variant_id' => $this->variantId, 'location_id' => $location, 'quantity' => -50])
            ->assertStatus(422)->assertJsonValidationErrors('quantity');

        $levels = $this->api('GET', 'stock/levels')->assertOk()->json('data');
        $this->assertSame(['Sac', 12, 12], [$levels[0]['title'], $levels[0]['on_hand'], $levels[0]['available']]);

        $journal = $this->api('GET', "stock/movements?variant_id={$this->variantId}")->json('data');
        $this->assertSame(['count', 'damaged', 'adjustment'], array_column($journal, 'type'));
        $this->assertSame('Taché', $journal[1]['note']);
        $this->assertSame($this->store->owner->name, $journal[0]['user']);

        $this->assertSame(['low' => 0, 'out' => 0, 'units' => 12, 'value' => 0], $this->api('GET', 'stock/summary')->json('data'));
    }

    public function test_receipts_transfers_and_counts(): void
    {
        $main = $this->locations()[0]['id'];
        $warehouse = $this->api('POST', 'stock/locations', ['name' => 'Entrepôt', 'type' => 'warehouse'])->json('data.id');

        $this->api('POST', 'stock/receipts', [
            'location_id' => $main, 'supplier_name' => 'Grossiste Adjamé', 'reference' => 'BL-1',
            'items' => [['variant_id' => $this->variantId, 'quantity' => 5, 'unit_cost' => 4000]],
        ])->assertCreated();
        $this->assertSame('Grossiste Adjamé', $this->api('GET', 'stock/receipts')->json('data.0.supplier'));
        $this->assertSame(['Grossiste Adjamé'], array_column($this->api('GET', 'stock/suppliers')->json('data'), 'name'));

        $transfer = $this->api('POST', 'stock/transfers', [
            'from_location_id' => $main, 'to_location_id' => $warehouse, 'items' => [['variant_id' => $this->variantId, 'quantity' => 6]],
        ])->assertCreated()->json('data.id');
        $this->api('POST', "stock/transfers/{$transfer}/receive")->assertOk();
        $this->assertSame('received', $this->api('GET', "stock/transfers/{$transfer}")->json('data.status'));

        $levels = collect($this->api('GET', "stock/levels?location_id={$warehouse}")->json('data'));
        $this->assertSame(6, $levels->first()['on_hand']);

        $count = $this->api('POST', 'stock/counts', ['location_id' => $warehouse])->assertCreated()->json('data.id');
        $this->api('POST', 'stock/counts', ['location_id' => $warehouse])->assertStatus(422);
        $this->assertSame(6, $this->api('GET', "stock/counts/{$count}")->json('data.items.0.expected'));
        $this->api('PUT', "stock/counts/{$count}/items", ['items' => [['variant_id' => $this->variantId, 'counted' => 4]]])->assertOk();
        $this->api('POST', "stock/counts/{$count}/complete")->assertOk();
        $this->assertSame(4, collect($this->api('GET', "stock/levels?location_id={$warehouse}")->json('data'))->first()['on_hand']);

        $this->assertSame([['id' => $this->variantId, 'title' => 'Sac', 'sku' => 'SAC', 'cost_price' => 4000, 'available' => 13]], $this->api('GET', 'stock/variants?search=sa')->json('data'));
    }

    public function test_only_stock_managers_reach_the_stock(): void
    {
        $this->actingAs($this->addMember($this->store, null, Role::ConfirmationAgent));
        $this->api('GET', 'stock/levels')->assertForbidden();

        $this->actingAs($this->addMember($this->store, null, Role::StockKeeper));
        $this->api('GET', 'stock/levels')->assertOk();

        $other = $this->createStore();
        $this->actingAs($other->owner);
        $this->storeApi($other, 'POST', 'stock/adjustments', ['variant_id' => $this->variantId, 'location_id' => 1, 'quantity' => 1])->assertNotFound();
    }
}
