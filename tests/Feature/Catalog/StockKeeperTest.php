<?php

namespace Tests\Feature\Catalog;

use App\Enums\StockLocationType;
use App\Enums\TransferStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Stock\StockKeeper;
use LogicException;
use Tests\TestCase;

class StockKeeperTest extends TestCase
{
    private Store $store;

    private Product $product;

    private StockLocation $shop;

    private StockLocation $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = $this->createStore();
        $this->product = app(CatalogWriter::class)->save($this->store, null, [
            'title' => 'Sac', 'variants' => [['price' => 10000, 'cost_price' => 4000]],
        ]);
        $this->shop = $this->keeper()->defaultLocation($this->store);
        $this->warehouse = StockLocation::create(['store_id' => $this->store->id, 'name' => 'Entrepôt Yopougon', 'type' => StockLocationType::Warehouse]);
    }

    private function keeper(): StockKeeper
    {
        return app(StockKeeper::class);
    }

    private function variant()
    {
        return $this->product->variants->first();
    }

    private function level(StockLocation $location): array
    {
        $level = StockLevel::where('variant_id', $this->variant()->id)->where('location_id', $location->id)->first();

        return [$level?->on_hand ?? 0, $level?->reserved ?? 0];
    }

    public function test_the_default_location_is_created_once(): void
    {
        $this->assertSame('Stock principal', $this->shop->name);
        $this->assertSame($this->shop->id, $this->keeper()->defaultLocation($this->store)->id);
    }

    public function test_adjustments_are_journaled_and_cannot_go_below_available(): void
    {
        $this->keeper()->adjust(null, $this->variant(), $this->shop, 10);
        $this->keeper()->adjust(null, $this->variant(), $this->shop, -3, note: 'Cassé');
        $this->assertSame([7, 0], $this->level($this->shop));

        $last = StockMovement::latest('id')->first();
        $this->assertSame([-3, 7, 'Cassé'], [$last->on_hand_change, $last->on_hand_after, $last->note]);

        $this->keeper()->reserve(null, $this->variant(), $this->shop, 5, $this->product);
        $this->expectException(BusinessRuleException::class);
        $this->keeper()->adjust(null, $this->variant(), $this->shop, -3);
    }

    public function test_the_journal_is_immutable(): void
    {
        $movement = $this->keeper()->adjust(null, $this->variant(), $this->shop, 1);

        $this->expectException(LogicException::class);
        $movement->update(['note' => 'x']);
    }

    public function test_reservations_ship_and_release(): void
    {
        $this->keeper()->adjust(null, $this->variant(), $this->shop, 5);

        $this->keeper()->reserve(null, $this->variant(), $this->shop, 3, $this->product);
        $this->assertSame([5, 3], $this->level($this->shop));

        $this->keeper()->ship(null, $this->variant(), $this->shop, 2, $this->product);
        $this->assertSame([3, 1], $this->level($this->shop));

        $this->keeper()->release(null, $this->variant(), $this->shop, 1, $this->product);
        $this->assertSame([3, 0], $this->level($this->shop));

        // Plus que le disponible : refusé, sauf vente en rupture autorisée
        try {
            $this->keeper()->reserve(null, $this->variant(), $this->shop, 4, $this->product);
            $this->fail('Réservation au-delà du stock acceptée');
        } catch (BusinessRuleException) {
        }
        $this->product->update(['allow_backorder' => true]);
        $this->keeper()->reserve(null, $this->variant()->setRelation('product', $this->product), $this->shop, 4, $this->product);
        $this->assertSame([3, 4], $this->level($this->shop));
    }

    public function test_a_receipt_adds_stock_and_updates_the_average_cost(): void
    {
        $this->keeper()->adjust(null, $this->variant(), $this->shop, 10);

        $receipt = $this->keeper()->receive(null, $this->shop, [['variant' => $this->variant(), 'quantity' => 10, 'unit_cost' => 5000]], ['reference' => 'BL-12']);

        $this->assertSame([20, 0], $this->level($this->shop));
        $this->assertSame(4500, $this->variant()->fresh()->cost_price); // (10 × 4000 + 10 × 5000) / 20
        $this->assertSame(50000, $receipt->total_cost);
        $this->assertSame('stock_receipt', StockMovement::latest('id')->value('reference_type'));
    }

    public function test_transfers_move_stock_between_locations(): void
    {
        $this->keeper()->adjust(null, $this->variant(), $this->shop, 10);

        $transfer = $this->keeper()->sendTransfer(null, $this->shop, $this->warehouse, [['variant' => $this->variant(), 'quantity' => 6]]);
        $this->assertSame([4, 0], $this->level($this->shop));
        $this->assertSame([0, 0], $this->level($this->warehouse));

        $this->keeper()->receiveTransfer(null, $transfer, [$this->variant()->id => 5]);
        $this->assertSame([5, 0], $this->level($this->warehouse));
        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame(5, $transfer->items()->first()->received_quantity);

        $this->expectException(BusinessRuleException::class);
        $this->keeper()->sendTransfer(null, $this->shop, $this->warehouse, [['variant' => $this->variant(), 'quantity' => 5]]);
    }

    public function test_a_cancelled_transfer_returns_to_its_origin(): void
    {
        $this->keeper()->adjust(null, $this->variant(), $this->shop, 10);
        $transfer = $this->keeper()->sendTransfer(null, $this->shop, $this->warehouse, [['variant' => $this->variant(), 'quantity' => 6]]);

        $this->keeper()->cancelTransfer(null, $transfer);

        $this->assertSame([10, 0], $this->level($this->shop));
        $this->assertSame(TransferStatus::Cancelled, $transfer->fresh()->status);
    }

    public function test_an_inventory_count_replaces_counted_quantities(): void
    {
        $this->keeper()->adjust(null, $this->variant(), $this->shop, 10);
        $other = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Ceinture', 'variants' => [['price' => 1, 'on_hand' => 3]]])->variants->first();

        $count = $this->keeper()->startCount(null, $this->shop);
        $this->assertSame([10, 3], $count->items()->orderBy('id')->pluck('expected')->all());

        $count->items()->where('variant_id', $this->variant()->id)->update(['counted' => 8]);
        $this->keeper()->completeCount(null, $count);

        $this->assertSame([8, 0], $this->level($this->shop));
        // Ligne non comptée : inchangée
        $this->assertSame(3, StockLevel::where('variant_id', $other->id)->value('on_hand'));

        $this->expectException(BusinessRuleException::class);
        $this->keeper()->completeCount(null, $count);
    }

    public function test_levels_can_be_rebuilt_from_the_journal(): void
    {
        $this->keeper()->adjust(null, $this->variant(), $this->shop, 10);
        $this->keeper()->reserve(null, $this->variant(), $this->shop, 2, $this->product);
        StockLevel::where('variant_id', $this->variant()->id)->update(['on_hand' => 99, 'reserved' => 99]);

        $this->keeper()->rebuild($this->variant());

        $this->assertSame([10, 2], $this->level($this->shop));
    }

    public function test_stock_never_crosses_stores(): void
    {
        $otherLocation = $this->keeper()->defaultLocation($this->createStore());

        $this->expectException(BusinessRuleException::class);
        $this->keeper()->adjust(null, $this->variant(), $otherLocation, 1);
    }
}
