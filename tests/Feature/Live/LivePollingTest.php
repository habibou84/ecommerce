<?php

namespace Tests\Feature\Live;

use App\Models\LiveItem;
use App\Models\LiveSession;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Live\LiveSales;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\TestCase;

/**
 * L'écran du live redemande l'état toutes les 3 secondes : « rien de neuf » (304, sans contenu) tant que rien ne
 * change, et les pièces restantes comptées pour tous les articles en une fois.
 */
class LivePollingTest extends TestCase
{
    use LiveProductsHavePhotos;

    private Store $store;

    private int $live;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giveProductsPhotos();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $writer = app(CatalogWriter::class);
        $a = $writer->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 50]]])->variants()->value('id');
        $b = $writer->save($this->store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 9000, 'on_hand' => 50]]])->variants()->value('id');
        $this->actingAs($this->store->owner);
        $this->live = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live', 'hold_minutes' => 30, 'items' => [
            ['variant_id' => $a, 'quantity' => 3], ['variant_id' => $b, 'quantity' => 2],
        ]])->assertCreated()->json('data.id');
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/start")->assertOk();
    }

    public function test_nothing_new_answers_304_until_a_reservation_arrives(): void
    {
        $first = $this->storeApi($this->store, 'GET', "lives/{$this->live}")->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('no-cache', (string) $first->headers->get('Cache-Control'));

        // Rien n'a changé : 304, sans contenu
        $this->withHeader('If-None-Match', $etag);
        $this->storeApi($this->store, 'GET', "lives/{$this->live}")->assertStatus(304)->assertNoContent(304);
        $this->storeApi($this->store, 'GET', "lives/{$this->live}/display")->assertOk();

        // Une pièce réservée : l'état change, le téléphone reçoit le nouveau
        $item = LiveItem::where('live_session_id', $this->live)->orderBy('position')->first();
        app(LiveSales::class)->reserve($item, ['name' => 'Aminata', 'phone' => '+2250701112233'], 'whatsapp');
        $this->storeApi($this->store, 'GET', "lives/{$this->live}")->assertOk()->assertJsonPath('data.items.0.remaining', 2);
    }

    public function test_remaining_pieces_are_counted_for_every_item_at_once(): void
    {
        $sales = app(LiveSales::class);
        $items = LiveSession::findOrFail($this->live)->items()->orderBy('position')->get();
        $sales->reserve($items[1], ['name' => 'Koffi', 'phone' => '+2250502223344'], 'whatsapp');
        $sales->reserve($items[1], ['name' => 'Mariam', 'phone' => '+2250703334455'], 'whatsapp');

        $this->assertSame([$items[0]->id => 3, $items[1]->id => 0], $sales->remainingFor($items));
        $this->assertSame([$sales->remaining($items[0]), $sales->remaining($items[1])], array_values($sales->remainingFor($items)));
        $this->assertSame(1, $sales->summary(LiveSession::findOrFail($this->live))['sold_out']);
    }
}
