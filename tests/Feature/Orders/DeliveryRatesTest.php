<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\CashMovement;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Place;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prix de livraison sans réglages (communes puis quartiers, prix retenus depuis les commandes, prix de départ) et
 * expéditions vers les autres villes (livraison jusqu'à la gare, frais de la compagnie connus au dépôt).
 */
class DeliveryRatesTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        Subscription::create(['store_id' => $this->store->id, 'plan_id' => Plan::where('code', 'pro')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    private function place(string $name): Place
    {
        return Place::whereNull('store_id')->where('name', $name)->firstOrFail();
    }

    private function order(string $place, array $extra = []): Order
    {
        return app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '+2250506070809',
            'delivery_method' => 'delivery', 'shipping_place_id' => $this->place($place)->id, 'shipping_address' => 'Près du marché',
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]], ...$extra,
        ], $this->store->owner);
    }

    /**
     * @return array{0: Courier, 1: User}
     */
    private function courier(): array
    {
        $created = $this->storeApi($this->store, 'POST', 'couriers', ['name' => 'Koffi', 'phone' => '0102030405', 'zone_ids' => [DeliveryZone::forStore($this->store->id)->value('id')]])->assertCreated();
        $token = basename($created->json('invitation_url'));
        $this->logout();
        $start = $this->storeApi($this->store, 'POST', "invitations/{$token}/accept", ['name' => 'Koffi', 'password' => 'motdepasse'])->assertStatus(202)->json();
        $this->storeApi($this->store, 'POST', "verifications/{$start['verification']['token']}/confirm", ['code' => $start['demo_code']])->assertOk();
        $courier = Courier::findOrFail($created->json('data.id'));
        $this->logout();
        $this->actingAs($this->store->owner);

        return [$courier, $courier->user];
    }

    public function test_prices_are_learned_from_orders_without_settings(): void
    {
        // Abidjan au prix de départ : les 13 communes à toucher, chacune avec son prix
        $rates = $this->storeApi($this->store, 'GET', 'delivery-rates')->assertOk()
            ->assertJsonPath('data.home.name', 'Abidjan')->assertJsonPath('data.base_fee', 1500)->json('data');
        $this->assertCount(13, $rates['places']);
        $this->assertSame([1500, false, true], [collect($rates['places'])->firstWhere('name', 'Cocody')['fee'], collect($rates['places'])->firstWhere('name', 'Cocody')['learned'], collect($rates['places'])->firstWhere('name', 'Cocody')['has_children']]);
        $this->assertContains('Bouaké', array_column($rates['cities'], 'name'));

        // Prix retenu pour Yopougon, puis seulement pour Niangon
        $yopougon = $this->place('Yopougon');
        $this->storeApi($this->store, 'POST', 'delivery-rates/remember', ['place_id' => $yopougon->id, 'fee' => 2000])->assertOk();
        $this->storeApi($this->store, 'POST', 'delivery-rates/remember', ['place_id' => $this->place('Niangon')->id, 'fee' => 2500])->assertOk();
        $quartiers = collect($this->storeApi($this->store, 'GET', "delivery-rates/places/{$yopougon->id}")->assertOk()->json('data.places'));
        $this->assertSame([2500, true], [$quartiers->firstWhere('name', 'Niangon')['fee'], $quartiers->firstWhere('name', 'Niangon')['learned']]);
        $this->assertSame([2000, false], [$quartiers->firstWhere('name', 'Selmer')['fee'], $quartiers->firstWhere('name', 'Selmer')['learned']]);

        // Les commandes prennent le prix le plus précis ; la commune la plus livrée passe en tête
        $this->assertSame(2500, (int) $this->order('Niangon')->shipping_fee);
        $this->assertSame(2000, (int) $this->order('Selmer')->shipping_fee);
        $this->assertSame(1500, (int) $this->order('Angré')->shipping_fee);
        $this->assertSame('Yopougon', $this->storeApi($this->store, 'GET', 'delivery-rates')->json('data.places.0.name'));

        // Retenir encore : le même prix change (pas de doublon) ; retiré, le quartier reprend celui de la commune
        $this->storeApi($this->store, 'POST', 'delivery-rates/remember', ['place_id' => $yopougon->id, 'fee' => 1800])->assertOk();
        $this->storeApi($this->store, 'DELETE', 'delivery-rates/places/'.$this->place('Niangon')->id)->assertOk();
        $this->assertSame(1800, (int) $this->order('Niangon')->shipping_fee);
        $this->assertSame(1, DeliveryZone::forStore($this->store->id)->where('name', 'Yopougon')->count());

        // Prix de départ changé (question à l'ouverture de la boutique)
        $this->storeApi($this->store, 'PUT', 'delivery-rates', ['base_fee' => 1000])->assertOk()->assertJsonPath('data.base_fee', 1000);
        $this->assertSame(1000, (int) $this->order('Angré')->shipping_fee);
    }

    public function test_expedition_to_another_city_is_paid_before_and_dropped_at_the_station(): void
    {
        $this->storeApi($this->store, 'POST', 'delivery-rates/remember', ['expedition' => true, 'fee' => 1500])->assertOk()
            ->assertJsonPath('data.expedition', ['fee' => 1500, 'prepay' => true]);

        // Bouaké : expédition, livraison jusqu'à la gare, transport payé à l'arrivée, paiement avant par défaut
        $order = $this->order('Bouaké');
        $this->assertSame([$this->place('Bouaké')->id, 'arrival', true, 1500, 16500], [$order->expedition_city_id, $order->expedition_payer, $order->expedition_prepay, (int) $order->shipping_fee, (int) $order->total]);
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.expedition.city', 'Bouaké')->assertJsonPath('data.expedition.prepay_due', 16500);
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        // Le livreur ne part pas avant le paiement
        [$courier, $user] = $this->courier();
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/shipments", ['courier_id' => $courier->id])->assertStatus(422)
            ->assertJsonPath('message', 'Paiement avant expédition : le client doit encore payer 16 500 F. Envoyez-lui le lien de paiement.');
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/payments", ['amount' => 16500, 'method' => 'wave'])->assertOk();
        $shipment = Shipment::findOrFail($this->storeApi($this->store, 'POST', "orders/{$order->id}/shipments", ['courier_id' => $courier->id])->assertCreated()->json('data.id'));

        // À la gare : compagnie et frais obligatoires, reçu en photo, frais avancés par le livreur
        $this->logout();
        $this->actingAs($user);
        $this->storeApi($this->store, 'GET', "courier/missions/{$shipment->id}")->assertJsonPath('data.expedition.city', 'Bouaké')->assertJsonPath('data.requires_code', false);
        $this->storeApi($this->store, 'POST', "courier/missions/{$shipment->id}/steps", ['status' => 'in_transit'])->assertOk();
        $this->storeApi($this->store, 'POST', "courier/missions/{$shipment->id}/steps", ['status' => 'delivered'])->assertStatus(422);
        $this->storeApi($this->store, 'POST', "courier/missions/{$shipment->id}/steps", [
            'status' => 'delivered', 'expedition_company' => 'UTB', 'expedition_fee' => 2000, 'expedition_ticket' => '4521', 'expedition_advanced' => true,
            'photo' => UploadedFile::fake()->image('recu.jpg'),
        ])->assertOk();

        $order->refresh();
        $this->assertSame([OrderStatus::Shipped, 'UTB', 2000, '4521', 16500], [$order->status, $order->expedition_company, (int) $order->expedition_fee, $order->expedition_ticket, (int) $order->total]);
        $this->assertSame(2000, (int) CourierEarning::where('type', 'expedition')->sum('amount'));
        $this->logout();
        $this->actingAs($this->store->owner);
        $detail = $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.expedition.receipt', true)->json('data.expedition');
        $this->assertStringContainsString(rawurlencode('par UTB, ticket n° 4521. Frais d\'expédition : 2 000 F, à payer à l\'arrivée.'), $detail['whatsapp_url']);
        $this->assertContains('UTB', $detail['companies']);
        $this->storeApi($this->store, 'GET', "orders/{$order->id}/expedition/receipt")->assertOk();

        // Colis récupéré : commande terminée
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/expedition/collected")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_expedition_fees_paid_before_departure_or_offered_by_the_store(): void
    {
        // Payés par le client avant le départ : ajoutés à la commande au dépôt (noté par le vendeur)
        $order = $this->order('Yamoussoukro', ['expedition_payer' => 'before', 'expedition_prepay' => false]);
        $this->assertSame([false, 'before'], [$order->expedition_prepay, $order->expedition_payer]);
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $total = (int) $order->fresh()->total;
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/expedition", ['company' => 'STIF', 'fee' => 3000, 'ticket' => 'B-12'])->assertOk()
            ->assertJsonPath('data.total', $total + 3000)->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.expedition.payer', 'before');

        // Offerts par la boutique : une dépense de transport
        $offered = $this->order('San-Pédro', ['expedition_payer' => 'store', 'expedition_prepay' => false]);
        $this->storeApi($this->store, 'POST', "orders/{$offered->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->storeApi($this->store, 'POST', "orders/{$offered->id}/expedition", ['company' => 'AVS', 'fee' => 2500])->assertOk();
        $this->assertSame(2500, (int) CashMovement::where('category', 'transport')->sum('amount'));

        // Une commande dans Abidjan n'est pas une expédition
        $this->storeApi($this->store, 'POST', 'orders/'.$this->order('Angré')->id.'/expedition', ['company' => 'UTB', 'fee' => 1000])->assertStatus(422);
    }
}
