<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Place;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\DeliveryCalendar;
use App\Services\Places\Places;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeliveryDateTest extends TestCase
{
    private Store $store;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['name' => 'Awa Mode', 'timezone' => 'Africa/Abidjan']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->variantId = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac pagne', 'variants' => [['price' => 9000, 'on_hand' => 50]]])->variants[0]->id;
        $this->actingAs($this->store->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'source' => 'whatsapp', 'customer_phone' => '0506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::where('name', 'Angré')->value('id'),
            'items' => [['variant_id' => $this->variantId, 'quantity' => 1]],
        ], $overrides);
    }

    public function test_earliest_date_follows_cutoff_and_closed_days(): void
    {
        $calendar = app(DeliveryCalendar::class);
        Carbon::setTestNow('2026-10-19 10:00:00'); // lundi, avant 14 h
        $this->assertSame('2026-10-19', $calendar->earliest($this->store));
        Carbon::setTestNow('2026-10-19 15:00:00'); // après l'heure limite par défaut
        $this->assertSame('2026-10-20', $calendar->earliest($this->store));

        // Mardi fermé, heure limite 16 h, Noël fermé
        $this->storeApi($this->store, 'PUT', 'delivery-calendar', ['cutoff' => '16:00', 'closed_weekdays' => [2], 'closed_dates' => ['2026-12-25']])
            ->assertOk()->assertJsonPath('data.cutoff', '16:00')->assertJsonPath('data.earliest', '2026-10-19');
        Carbon::setTestNow('2026-10-19 17:00:00');
        $this->assertSame('2026-10-21', $calendar->earliest($this->store->fresh()));
        Carbon::setTestNow('2026-12-24 18:00:00');
        $this->assertSame('2026-12-26', $calendar->earliest($this->store->fresh()));
        $this->storeApi($this->store, 'PUT', 'delivery-calendar', ['cutoff' => '25:00', 'closed_weekdays' => [9], 'closed_dates' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['cutoff', 'closed_weekdays.0']);

        $days = $this->storeApi($this->store, 'POST', 'orders/delivery-options', [])->assertOk()->json('data.calendar');
        $this->assertSame('2026-12-26', $days['earliest']);
        $this->assertFalse($days['days'][1]['open']); // 25 décembre
    }

    public function test_every_delivery_order_has_a_date(): void
    {
        Carbon::setTestNow('2026-10-19 15:00:00');

        // Sans date : la première date possible ; date passée refusée ; retrait au magasin sans date
        $id = $this->storeApi($this->store, 'POST', 'orders', $this->payload())->assertCreated()->json('data.id');
        $this->assertSame('2026-10-20', Order::findOrFail($id)->scheduled_date->toDateString());
        $this->storeApi($this->store, 'POST', 'orders', $this->payload(['scheduled_date' => '2026-10-18']))->assertStatus(422)->assertJsonValidationErrors('scheduled_date');
        $id = $this->storeApi($this->store, 'POST', 'orders', $this->payload(['scheduled_date' => '2026-10-23']))->assertCreated()->json('data.id');
        $this->assertSame('2026-10-23', Order::findOrFail($id)->scheduled_date->toDateString());
        $pickup = $this->storeApi($this->store, 'POST', 'orders', $this->payload(['delivery_method' => 'pickup', 'shipping_place_id' => null]))->assertCreated()->json('data.id');
        $this->assertNull(Order::findOrFail($pickup)->scheduled_date);

        // Modification : pas de date passée, l'ancienne date d'une commande en retard reste acceptée telle quelle
        $this->storeApi($this->store, 'PUT', "orders/{$id}", ['scheduled_date' => '2026-10-01'])->assertStatus(422);
        Order::findOrFail($id)->forceFill(['scheduled_date' => '2026-10-10'])->save();
        $this->storeApi($this->store, 'PUT', "orders/{$id}", ['scheduled_date' => '2026-10-10', 'customer_note' => 'Appeler avant'])->assertOk();
    }
}
