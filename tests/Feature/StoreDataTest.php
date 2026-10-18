<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\StoreStatus;
use App\Models\Order;
use App\Models\Place;
use App\Models\ScheduledReport;
use App\Models\Store;
use App\Models\StoreExport;
use App\Notifications\ExportReady;
use App\Services\Accounts\StoreDataExport;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Tests\TestCase;
use ZipArchive;

class StoreDataTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
    }

    private function order(): Order
    {
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'sku' => 'ROBE', 'on_hand' => 5]]]);

        return app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $robe->variants[0]->id, 'quantity' => 2]],
        ], $this->store->owner);
    }

    public function test_the_owner_exports_all_the_data(): void
    {
        $order = $this->order();
        // Réservé au propriétaire
        $manager = $this->addMember($this->store);
        $this->logout();
        $this->actingAs($manager);
        $this->storeApi($this->store, 'POST', 'store-data/exports')->assertForbidden();
        $this->logout();
        $this->actingAs($this->store->owner);

        $this->storeApi($this->store, 'POST', 'store-data/exports')->assertStatus(202);
        $export = StoreExport::sole();
        $this->assertSame('ready', $export->status);
        $this->storeApi($this->store, 'GET', 'store-data/exports')->assertOk()->assertJsonPath('data.0.status', 'ready');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->store->owner_id, 'type' => ExportReady::class]);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(app(StoreDataExport::class)->absolutePath($export)));
        $this->assertNotFalse($zip->locateName('commandes.csv'));
        $this->assertNotFalse($zip->locateName('produits.csv'));
        $orders = array_map(fn ($l) => str_getcsv($l, ';'), array_filter(explode("\n", $zip->getFromName('commandes.csv'))));
        $this->assertSame("\u{FEFF}Numéro", $orders[0][0]);
        $this->assertSame(['#'.$order->number, 'Fatou Diallo', '+2250506070809', (string) $order->total], [$orders[1][0], $orders[1][5], $orders[1][6], $orders[1][17]]);
        $this->assertStringContainsString('ROBE;', $zip->getFromName('produits.csv'));
        $this->assertStringContainsString('Fatou Diallo', $zip->getFromName('clients.csv'));
        $zip->close();

        $this->storeApi($this->store, 'GET', "store-data/exports/{$export->id}/download")->assertOk()->assertDownload('donnees-awa-'.now()->format('Y-m-d').'.zip');

        // Après 7 jours : supprimé
        $this->travel(8)->days();
        $this->artisan('exports:purge')->assertSuccessful();
        $this->assertSame(0, StoreExport::count());
        $this->assertFileDoesNotExist(app(StoreDataExport::class)->absolutePath($export));
    }

    public function test_the_owner_closes_the_store(): void
    {
        $order = $this->order();
        $this->storeApi($this->store, 'PUT', 'reports/schedule', ['channels' => ['whatsapp' => ['is_active' => true, 'send_at' => '20:00']]])->assertOk();
        app(OrderDesk::class)->transition($order, OrderStatus::Shipped, $this->store->owner);

        $this->storeApi($this->store, 'POST', 'store-data/close', ['confirmation' => 'Autre nom'])->assertStatus(422);
        // Une commande en cours de livraison bloque la fermeture
        $this->storeApi($this->store, 'POST', 'store-data/close', ['confirmation' => 'awa mode'])->assertStatus(422);
        app(OrderDesk::class)->transition($order->fresh(), OrderStatus::Completed, $this->store->owner);

        $this->storeApi($this->store, 'POST', 'store-data/close', ['confirmation' => 'awa mode', 'reason' => 'Je change d\'activité'])->assertOk()->assertJsonPath('data.status', 'closed');
        $store = $this->store->fresh();
        $this->assertSame([StoreStatus::Closed, false, 'Je change d\'activité'], [$store->status, $store->storefront_enabled, $store->closed_reason]);
        $this->assertFalse(ScheduledReport::withoutGlobalScopes()->where('store_id', $store->id)->value('is_active'));
        // Plus rien n'est accessible à l'adresse de la boutique
        $this->storeApi($store, 'GET', 'orders')->assertForbidden();
    }
}
