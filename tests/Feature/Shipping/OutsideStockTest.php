<?php

namespace Tests\Feature\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\CarrierConnection;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Place;
use App\Models\Shipment;
use App\Models\Store;
use App\Notifications\OrderAlertNotice;
use App\Services\Catalog\CatalogWriter;
use App\Services\Couriers\CashDesk;
use App\Services\Orders\OrderAlerts;
use App\Services\Places\Places;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Argent et colis chez les livreurs et les entreprises de livraison : accueil, alertes, file « colis à rapporter ».
 */
class OutsideStockTest extends TestCase
{
    private Store $store;

    private int $variantId;

    private Courier $koffi;

    private CarrierConnection $moto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-19 08:00:00'); // lundi, boutique à Abidjan (UTC)
        $this->store = $this->createStore(['name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->variantId = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac pagne', 'variants' => [['price' => 9000, 'on_hand' => 50]]])->variants[0]->id;
        $this->actingAs($this->store->owner);
        $this->koffi = Courier::create(['store_id' => $this->store->id, 'name' => 'Koffi', 'phone' => '+2250102030405', 'status' => 'active', 'cash_limit' => 50000]);
        $this->moto = CarrierConnection::create(['store_id' => $this->store->id, 'driver' => CarrierConnection::MANUAL, 'name' => 'Moto Express', 'status' => 'active', 'webhook_uuid' => (string) Str::uuid()]);
        Notification::fake();
    }

    private function order(): Order
    {
        $id = $this->storeApi($this->store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '0506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::where('name', 'Angré')->value('id'),
            'items' => [['variant_id' => $this->variantId, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    private function shipment(ShipmentStatus $status, array $attributes = []): Shipment
    {
        return Shipment::create(array_merge(['store_id' => $this->store->id, 'order_id' => $this->order()->id, 'mode' => 'own_courier', 'status' => $status,
            'courier_id' => $this->koffi->id, 'idempotency_key' => (string) Str::uuid(), 'requested_at' => now()], $attributes));
    }

    private function collect(int $amount): void
    {
        $shipment = $this->shipment(ShipmentStatus::Delivered, ['cod_amount' => $amount, 'delivered_at' => now()]);
        CashCollection::create(['store_id' => $this->store->id, 'shipment_id' => $shipment->id, 'courier_id' => $this->koffi->id, 'amount' => $amount, 'method' => 'cash', 'collected_at' => now()]);
    }

    private function carrierDelivery(int $cod, int $fee): void
    {
        $this->shipment(ShipmentStatus::Delivered, ['mode' => 'manual_carrier', 'courier_id' => null, 'carrier_connection_id' => $this->moto->id,
            'cod_amount' => $cod, 'carrier_fee' => $fee, 'delivered_at' => now()]);
    }

    /**
     * Alertes reçues par le propriétaire, dans l'ordre : « type : titre ».
     *
     * @return list<string>
     */
    private function alertsAt(string $at): array
    {
        $this->travelTo($at);
        Notification::fake();
        app(OrderAlerts::class)->forStore($this->store->fresh());

        return Notification::sent($this->store->owner, OrderAlertNotice::class)
            ->map(fn (OrderAlertNotice $n) => str_replace('order_alert_', '', $n->toArray($this->store->owner)['kind']).' : '.$n->toArray($this->store->owner)['title'])
            // Seulement les alertes de l'argent et des colis (les commandes créées ici ont aussi les leurs)
            ->filter(fn (string $line) => Str::startsWith($line, ['courier_cash', 'carrier_payout', 'returns']))
            ->values()->all();
    }

    public function test_dashboard_shows_money_and_parcels_outside(): void
    {
        $this->collect(40000);
        $this->collect(25000);
        $this->shipment(ShipmentStatus::OutForDelivery);
        $this->shipment(ShipmentStatus::Failed);
        $this->carrierDelivery(30000, 2000);
        $this->shipment(ShipmentStatus::InTransit, ['mode' => 'manual_carrier', 'courier_id' => null, 'carrier_connection_id' => $this->moto->id]);

        $this->travelTo('2026-10-20 10:00:00');
        $data = $this->storeApi($this->store, 'GET', 'dashboard')->assertOk()->json('data');

        $this->assertSame(65000, $data['couriers']['cash']);
        $this->assertSame(1, $data['couriers']['holders']);
        $this->assertSame(1, $data['couriers']['on_the_way']);
        $this->assertSame(1, $data['couriers']['to_bring_back']);
        $this->assertSame(['Koffi'], $data['couriers']['over_limit']);
        // Gardé depuis 26 h : en retard (24 h), pas encore rouge (48 h)
        $this->assertSame([['courier_id' => $this->koffi->id, 'name' => 'Koffi', 'held' => 65000, 'hours' => 26, 'since' => '2026-10-19T08:00:00+00:00', 'level' => 1]], $data['couriers']['late']);

        $this->assertSame(28000, $data['carriers']['amount']);
        $this->assertSame(1, $data['carriers']['on_the_way']);
        $this->assertSame([], $data['carriers']['overdue']);

        // La file des commandes à rapporter
        $this->assertCount(1, $this->storeApi($this->store, 'GET', 'orders?alert=to_bring_back')->json('data'));
    }

    public function test_dashboard_hides_sections_without_couriers_or_carriers(): void
    {
        $this->koffi->delete();
        $this->moto->delete();

        $data = $this->storeApi($this->store, 'GET', 'dashboard')->assertOk()->json('data');
        $this->assertNull($data['couriers']);
        $this->assertNull($data['carriers']);
    }

    public function test_courier_cash_carrier_payout_and_returns_alerts(): void
    {
        $this->collect(60000);
        $this->carrierDelivery(120000, 5000);
        $this->shipment(ShipmentStatus::Returning);

        $this->assertSame([], $this->alertsAt('2026-10-19 20:00:00'));
        // 24 h : argent chez Koffi
        $this->assertSame(['courier_cash : Koffi vous doit 60 000 F depuis hier'], $this->alertsAt('2026-10-20 08:30:00'));
        $this->assertSame([], $this->alertsAt('2026-10-20 12:00:00'));
        // 48 h : au propriétaire (rouge), et le colis pas rapporté
        $this->assertSame([
            'courier_cash_escalated : Koffi vous doit 60 000 F depuis le 19 octobre',
            'returns : Un colis pas encore rapporté',
        ], $this->alertsAt('2026-10-21 08:30:00'));

        // Versé : plus rien chez Koffi
        app(CashDesk::class)->remit($this->koffi, 60000, 'cash', null, $this->store->owner);
        // 7 jours : l'entreprise n'a pas reversé ; le colis toujours pas rapporté après trois fois le délai : au propriétaire
        $this->assertSame([
            'carrier_payout : Moto Express vous doit 115 000 F depuis le 19 octobre',
            'returns_escalated : Un colis pas encore rapporté depuis 6 jours',
        ], $this->alertsAt('2026-10-26 08:30:00'));
        $this->assertSame(['carrier_payout_escalated : Moto Express vous doit 115 000 F depuis le 19 octobre'], $this->alertsAt('2026-11-02 08:30:00'));

        // Rien la nuit
        $this->collect(5000);
        $this->assertSame([], $this->alertsAt('2026-11-03 22:00:00'));
    }

    public function test_alert_delays_are_settings(): void
    {
        $this->carrierDelivery(10000, 1000);
        $this->collect(8000);
        $this->storeApi($this->store, 'PUT', 'alert-settings', array_merge(app(OrderAlerts::class)->settings($this->store->fresh()), [
            'carrier_payout' => ['on' => true, 'days' => 2], 'courier_cash' => ['on' => false, 'hours' => 12], 'cutoff_time' => '14:00',
        ]))->assertOk()->assertJsonPath('data.carrier_payout.days', 2);

        $this->assertSame(['carrier_payout : Moto Express vous doit 9 000 F depuis le 19 octobre'], $this->alertsAt('2026-10-21 09:00:00'));
        $this->storeApi($this->store, 'GET', 'carrier-balances')->assertJsonPath('overdue_days', 2)->assertJsonPath('data.0.overdue_count', 1);

        $this->storeApi($this->store, 'PUT', 'alert-settings', array_merge(app(OrderAlerts::class)->settings($this->store->fresh()), [
            'returns' => ['on' => true, 'hours' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors('returns.hours');
    }
}
