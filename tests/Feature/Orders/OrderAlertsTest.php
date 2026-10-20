<?php

namespace Tests\Feature\Orders;

use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Place;
use App\Models\Shipment;
use App\Models\Store;
use App\Notifications\OrderAlertNotice;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderAlerts;
use App\Services\Places\Places;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderAlertsTest extends TestCase
{
    private Store $store;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-19 08:00:00'); // lundi, boutique à Abidjan (UTC)
        $this->store = $this->createStore(['name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->variantId = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac pagne', 'variants' => [['price' => 9000, 'on_hand' => 50]]])->variants[0]->id;
        $this->actingAs($this->store->owner);
        Notification::fake();
    }

    private function order(array $overrides = []): Order
    {
        $id = $this->storeApi($this->store, 'POST', 'orders', array_merge([
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '0506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::where('name', 'Angré')->value('id'),
            'items' => [['variant_id' => $this->variantId, 'quantity' => 1]],
        ], $overrides))->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    private function shipment(Order $order, ShipmentStatus $status): Shipment
    {
        return Shipment::create(['store_id' => $this->store->id, 'order_id' => $order->id, 'mode' => 'manual_carrier', 'status' => $status,
            'idempotency_key' => (string) Str::uuid(), 'requested_at' => now(), 'scheduled_date' => $order->scheduled_date]);
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
            ->values()->all();
    }

    public function test_unassigned_order_alerts_once_then_escalates_and_cutoff_reminds(): void
    {
        $this->travelTo('2026-10-19 09:00:00');
        $today = $this->order(['confirmed' => true, 'scheduled_date' => '2026-10-19']);
        $this->order(['confirmed' => true, 'scheduled_date' => '2026-10-21']); // un autre jour : pas d'alerte aujourd'hui

        // Point du matin, une seule fois ; pas encore d'alerte « sans livreur » avant le délai (1 h)
        $this->assertSame(["morning : Aujourd'hui : 1 commande à livrer"], $this->alertsAt('2026-10-19 09:30:00'));
        $this->assertSame([], $this->alertsAt('2026-10-19 09:40:00'));

        $this->assertSame(['unassigned : Une commande confirmée attend un livreur'], $this->alertsAt('2026-10-19 10:05:00'));
        $this->assertSame([], $this->alertsAt('2026-10-19 10:10:00'));
        // Trois fois le délai : le propriétaire est prévenu à part
        $this->assertSame(['unassigned_escalated : Une commande confirmée attend un livreur depuis 3 h'], $this->alertsAt('2026-10-19 12:05:00'));
        // Heure limite (14 h par défaut) : les commandes du jour pas encore parties
        $this->assertSame(['cutoff : Heure limite passée (14 h 00)'], $this->alertsAt('2026-10-19 14:05:00'));
        // Rien la nuit
        $this->assertSame([], $this->alertsAt('2026-10-19 22:00:00'));

        // La liste des commandes filtrée sur l'alerte
        $this->assertSame([$today->id], collect($this->storeApi($this->store, 'GET', 'orders?alert=unassigned')->json('data'))->pluck('id')->all());
        $this->storeApi($this->store, 'GET', 'dashboard')->assertJsonPath('data.orders.waiting.unassigned', 1);

        // Confiée à une entreprise : plus « sans livreur » ; course annulée, l'attente repart de l'annulation
        $shipment = $this->shipment($today, ShipmentStatus::Requested);
        $this->assertSame(0, OrderAlerts::unassigned(Order::query())->count());
        $this->travelTo('2026-10-20 09:00:00');
        $shipment->update(['status' => ShipmentStatus::Cancelled]);
        $this->assertSame([], array_values(array_filter($this->alertsAt('2026-10-20 09:30:00'), fn ($a) => str_starts_with($a, 'unassigned'))));
        $this->assertContains('unassigned : Une commande confirmée attend un livreur', $this->alertsAt('2026-10-20 10:05:00'));
    }

    public function test_pickup_problem_confirmation_callback_and_stalled_alerts(): void
    {
        $this->store->forceFill(['alert_settings' => ['morning' => ['on' => false], 'cutoff' => ['on' => false], 'unassigned' => ['on' => false]]])->save();
        $this->travelTo('2026-10-19 09:00:00');
        $picked = $this->shipment($this->order(['confirmed' => true, 'scheduled_date' => '2026-10-19']), ShipmentStatus::Requested);
        $failed = $this->shipment($this->order(['confirmed' => true, 'scheduled_date' => '2026-10-19']), ShipmentStatus::OutForDelivery);
        $this->order(); // à confirmer
        $callback = $this->order(['customer_name' => 'Awa']);
        $callback->forceFill(['callback_at' => '2026-10-19 11:30:00'])->save();

        $this->assertSame([
            'pickup : Un colis pas encore ramassé',
            'confirm : Une commande attend d\'être confirmée',
        ], $this->alertsAt('2026-10-19 11:05:00'));

        $this->assertSame(['callback : Rappeler Awa maintenant'], $this->alertsAt('2026-10-19 11:35:00'));

        $this->travelTo('2026-10-19 12:00:00');
        $failed->update(['status' => ShipmentStatus::Failed]);
        $this->assertSame([], $this->alertsAt('2026-10-19 12:20:00'));
        $this->assertSame(['unhandled : Un problème de livraison sans suite'], $this->alertsAt('2026-10-19 12:35:00'));
        $this->assertSame([$failed->order_id], collect($this->storeApi($this->store, 'GET', 'orders?alert=unhandled')->json('data'))->pluck('id')->all());

        // Fin des livraisons (19 h) : colis encore en route
        $picked->update(['status' => ShipmentStatus::OutForDelivery]);
        // (et le problème toujours sans suite, trois fois le délai passé, remonte au propriétaire)
        $this->assertSame(['stalled : Un colis encore en route', 'unhandled_escalated : Un problème de livraison sans suite depuis 1 h 30'],
            $this->alertsAt('2026-10-19 19:05:00'));

        // Une alerte coupée ne part plus
        $this->storeApi($this->store, 'PUT', 'alert-settings', array_merge(app(OrderAlerts::class)->settings($this->store->fresh()), [
            'pickup' => ['on' => false, 'minutes' => 90],
        ]))->assertOk()->assertJsonPath('data.pickup.minutes', 90)->assertJsonPath('data.cutoff_time', '14:00');
        $this->assertFalse(app(OrderAlerts::class)->settings($this->store->fresh())['pickup']['on']);
    }
}
