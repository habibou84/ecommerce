<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\CarrierConnection;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Place;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use Tests\TestCase;

class OrderListTest extends TestCase
{
    private Store $store;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->variantId = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac pagne', 'variants' => [['price' => 9000, 'on_hand' => 50]]])->variants[0]->id;
        $this->actingAs($this->store->owner);
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->storeApi($this->store, $method, $path, $data);
    }

    private function order(array $overrides = []): Order
    {
        $id = $this->api('POST', 'orders', array_merge([
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '0506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::where('name', 'Angré')->value('id'),
            'items' => [['variant_id' => $this->variantId, 'quantity' => 2]],
        ], $overrides))->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    public function test_list_by_period_late_tab_summary_and_row_details(): void
    {
        $today = $this->order(['confirmed' => true, 'scheduled_date' => today()->toDateString()]);
        $late = $this->order(['confirmed' => true]);
        $late->forceFill(['scheduled_date' => today()->subDays(2), 'created_at' => now()->subDays(3)])->save();
        $old = $this->order();
        $old->forceFill(['created_at' => now()->subMonths(2), 'scheduled_date' => today()->addDays(3)])->save();

        $list = $this->api('GET', 'orders?period=7d')->assertOk();
        $this->assertSame([$today->id, $late->id], collect($list->json('data'))->pluck('id')->sort()->values()->all());
        $list->assertJsonPath('counts.late', 1)->assertJsonPath('summary.count', 2)->assertJsonPath('summary.due_today', 1)
            ->assertJsonPath('summary.to_collect', 2 * 19500);
        $row = collect($list->json('data'))->firstWhere('id', $today->id);
        $this->assertSame(['2 articles · Sac pagne', today()->toDateString(), false], [$row['items_label'], $row['scheduled_date'], $row['is_late']]);

        $this->assertSame([$late->id], collect($this->api('GET', 'orders?status=late')->json('data'))->pluck('id')->all());
        // Par date de livraison, tri par date de livraison
        $this->assertSame([$today->id], collect($this->api('GET', 'orders?period=today&date_field=scheduled')->json('data'))->pluck('id')->all());
        $this->assertSame([$today->id, $late->id], collect($this->api('GET', 'orders?period=7d&date_field=created&sort=oldest')->json('data'))->pluck('id')->all());

        // Par défaut : par jour de livraison, le plus lointain en haut, les dates à fixer d'abord
        $undated = $this->order();
        $undated->forceFill(['scheduled_date' => null, 'created_at' => now()->subMonth()])->save();
        $list = $this->api('GET', 'orders')->assertOk();
        $this->assertSame([$undated->id, $old->id, $today->id, $late->id], collect($list->json('data'))->pluck('id')->all());
        $this->assertTrue($list->json('data.0.needs_date'));
        $this->assertSame([null, today()->addDays(3)->toDateString()], [$list->json('data.0.day'), $list->json('data.1.day')]);
        $list->assertJsonPath('days.none.count', 1)->assertJsonPath('days.'.today()->toDateString().'.amount', 19500);
        // Un jour coupé entre deux pages garde son vrai total
        $page = $this->api('GET', 'orders?per_page=1&page=2')->assertOk();
        $this->assertSame([$old->id], collect($page->json('data'))->pluck('id')->all());
        $page->assertJsonPath('days.'.today()->addDays(3)->toDateString().'.count', 1)->assertJsonPath('meta.last_page', 4);
        $this->assertSame(today()->subMonth()->toDateString(), $this->api('GET', 'orders?sort=recent')->json('data.0.day'));
        $this->assertSame([$old->id], collect($this->api('GET', 'orders?period=custom&from='.today()->addDays(3)->toDateString())->json('data'))->pluck('id')->all());
        $this->assertSame(4, $this->api('GET', 'orders?payment=to_collect')->json('summary.count'));
    }

    public function test_deliverer_filter_bulk_actions_labels_and_export(): void
    {
        $courier = Courier::create(['store_id' => $this->store->id, 'name' => 'Koffi', 'phone' => '+2250102030405', 'status' => 'active', 'is_available' => true]);
        $carrier = CarrierConnection::findOrFail($this->api('POST', 'carriers/manual', ['name' => 'Moto Express'])->assertCreated()->json('data.id'));
        $pending = $this->order();
        $confirmed = $this->order(['confirmed' => true]);
        $other = $this->order(['confirmed' => true]);

        // Confier à un livreur : gardé pour la confirmation, ou course demandée tout de suite
        $result = $this->api('POST', 'orders/bulk', ['ids' => [$pending->id, $confirmed->id], 'action' => 'assign', 'courier_id' => $courier->id])->assertOk();
        $result->assertJsonPath('data.done', 2)->assertJsonPath('data.skipped', []);
        $this->assertSame($courier->id, $pending->fresh()->planned_courier_id);
        $this->assertSame($courier->id, $confirmed->fresh()->shipments->sole()->courier_id);
        $this->api('POST', 'orders/bulk', ['ids' => [$confirmed->id], 'action' => 'assign', 'carrier_connection_id' => $carrier->id])
            ->assertJsonPath('data.done', 0)->assertJsonPath('data.skipped.0.reason', 'Déjà confiée');

        $this->assertSame([$pending->id, $confirmed->id], collect($this->api('GET', "orders?deliverer=courier:{$courier->id}")->json('data'))->pluck('id')->sort()->values()->all());
        $this->assertSame([$other->id], collect($this->api('GET', 'orders?deliverer=none')->json('data'))->pluck('id')->all());
        $this->assertSame('Koffi', collect($this->api('GET', 'orders')->json('data'))->firstWhere('id', $pending->id)['deliverer']);

        // Changer le statut : chaque commande à part
        $this->api('POST', 'orders/bulk', ['ids' => [$pending->id, $other->id], 'action' => 'status', 'status' => 'processing'])
            ->assertJsonPath('data.done', 1)->assertJsonPath('data.skipped.0.reference', $pending->reference());
        $this->assertSame(OrderStatus::Processing, $other->fresh()->status);
        $this->api('POST', 'orders/bulk', ['ids' => [$pending->id], 'action' => 'status', 'status' => 'cancelled'])->assertStatus(422)->assertJsonValidationErrors('reason');

        // Étiquettes en série et export
        $this->get($this->storeUrl($this->store, "/api/v1/orders/labels?ids[]={$pending->id}&ids[]={$other->id}"))->assertOk()->assertHeader('content-type', 'application/pdf');
        $csv = $this->get($this->storeUrl($this->store, '/api/v1/orders/export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('N°;Date;Client', $csv);
        $this->assertStringContainsString('2 × Sac pagne', $csv);
        $this->assertSame(4, substr_count(trim($csv), "\n") + 1);
    }
}
