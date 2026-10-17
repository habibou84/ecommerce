<?php

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Notifications\DailyReportMail;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Reports\DailyAdvice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyAdviceTest extends TestCase
{
    private Store $store;

    private int $phone = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
    }

    private function order(Product $product, int $quantity = 1, bool $confirmed = true): Order
    {
        return app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+22505060708'.$this->phone++, 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => $confirmed,
            'items' => [['variant_id' => $product->variants[0]->id, 'quantity' => $quantity]],
        ], $this->store->owner);
    }

    private function finish(Order $order, OrderStatus $status): void
    {
        $desk = app(OrderDesk::class);
        $desk->transition($desk->transition($order, OrderStatus::Shipped, $this->store->owner), $status, $this->store->owner);
    }

    public function test_three_tips_from_the_real_figures(): void
    {
        $writer = app(CatalogWriter::class);
        $robe = $writer->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 20]]]);
        $sac = $writer->save($this->store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 9000, 'on_hand' => 8]]]);
        $sac->forceFill(['created_at' => now()->subDays(45)])->saveQuietly();

        // Une commande à confirmer depuis 2 jours
        $this->travel(-2)->days();
        $this->order($robe, confirmed: false);
        $this->travelBack();

        // Cette semaine : 7 commandes de robes, dont 3 revenues (zone Abidjan) ; plus que 2 robes disponibles
        foreach (range(1, 7) as $i) {
            $order = $this->order($robe, $i === 7 ? 11 : 1);
            if ($i <= 6) {
                $this->finish($order, $i <= 3 ? OrderStatus::Returned : OrderStatus::Completed);
            }
        }

        $tips = app(DailyAdvice::class)->tips($this->store);
        $this->assertCount(3, $tips);
        // Le plus important d'abord : l'article qui va manquer (vendus = non annulés et non retournés)
        $this->assertSame('Réapprovisionnez « Robe wax » : 15 vendus cette semaine, plus que 5 en stock (environ 2 jours)', $tips[0]);
        $this->assertSame('1 commande attend une confirmation depuis plus d\'un jour : appelez ces clients demain matin, le risque d\'annulation augmente.', $tips[1]);
        $this->assertSame('À Abidjan, 50 % des colis sont revenus ce mois-ci : confirmez par appel avant d\'expédier, ou demandez un acompte.', $tips[2]);

        // Hors des trois retenus mais calculé : l'article qui dort
        $store = $this->store;
        $sleeping = (fn () => $this->sleepingStock($store, now()->toImmutable()))->call(app(DailyAdvice::class));
        $this->assertStringContainsString('« Sac en pagne » ne s\'est pas vendu depuis un mois', $sleeping[1]);

        // Accueil et aperçu du rapport
        $this->storeApi($this->store, 'GET', 'dashboard')->assertJsonPath('data.tips', $tips);
        $this->assertStringContainsString("💡 Conseils :\n1. Réapprovisionnez « Robe wax »", $this->storeApi($this->store, 'GET', 'reports/schedule')->json('data.preview'));
    }

    public function test_no_tip_when_everything_is_fine(): void
    {
        app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 20]]]);
        $this->assertSame([], app(DailyAdvice::class)->tips($this->store));
        $this->assertStringNotContainsString('Conseils', $this->storeApi($this->store, 'GET', 'reports/schedule')->json('data.preview'));
    }

    public function test_tips_go_with_the_evening_report(): void
    {
        Notification::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 3]]]);
        $this->order($robe, 2);
        $this->store->owner->forceFill(['email' => 'awa@example.com'])->save();
        WhatsappAccount::create(['store_id' => $this->store->id, 'phone_number_id' => '123', 'access_token' => 'tok', 'app_secret' => 's', 'verify_token' => Str::random(20),
            'webhook_uuid' => (string) Str::uuid(), 'templates' => ['daily_report' => 'jm_rapport_du_jour', 'daily_tips' => 'jm_conseils_du_soir'],
            'automations' => ['daily_report' => true, 'daily_tips' => true], 'status' => 'active']);
        $this->storeApi($this->store, 'PUT', 'reports/schedule', ['channels' => ['email' => ['is_active' => true, 'send_at' => '00:00'], 'whatsapp' => ['is_active' => true, 'send_at' => '00:00']]])->assertOk();

        $this->artisan('reports:send')->assertSuccessful();
        Notification::assertSentTo($this->store->owner, DailyReportMail::class, fn ($n) => str_contains($n->text, '1. Réapprovisionnez « Robe wax » : 2 vendus cette semaine, plus que 1 en stock'));
        Http::assertSent(fn ($r) => ($r['template']['name'] ?? null) === 'jm_conseils_du_soir'
            && $r['template']['components'][0]['parameters'][0]['text'] === 'Awa Mode'
            && str_starts_with($r['template']['components'][0]['parameters'][1]['text'], '1) Réapprovisionnez « Robe wax »')
            && ! str_contains($r['template']['components'][0]['parameters'][1]['text'], "\n"));
    }
}
