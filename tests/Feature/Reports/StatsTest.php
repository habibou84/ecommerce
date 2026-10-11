<?php

namespace Tests\Feature\Reports;

use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\CourierPayRule;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Place;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ScheduledReport;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\WhatsappAccount;
use App\Notifications\DailyReportMail;
use App\Services\Catalog\CatalogWriter;
use App\Services\Couriers\CourierDesk;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Shipping\ShipmentDesk;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatsTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private int $phone = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'cost_price' => 9000, 'on_hand' => 50]]]);
        $this->actingAs($this->store->owner);
    }

    private function pro(): void
    {
        Subscription::create(['store_id' => $this->store->id, 'plan_id' => Plan::where('code', 'pro')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $this->store->unsetRelation('currentSubscription');
    }

    private function order(string $source = 'whatsapp', int $quantity = 1): Order
    {
        return app(OrderDesk::class)->create($this->store, [
            'source' => $source, 'customer_name' => 'Fatou', 'customer_phone' => '+22505060708'.$this->phone++, 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => $quantity]],
        ], $this->store->owner);
    }

    public function test_advanced_stats(): void
    {
        $this->storeApi($this->store, 'GET', 'stats')->assertForbidden()->assertJsonPath('upgrade', true);
        $this->pro();

        $rule = CourierPayRule::create(['store_id' => $this->store->id, 'name' => 'Course', 'type' => 'per_delivery', 'amount' => 1000]);
        $courier = Courier::create(['store_id' => $this->store->id, 'name' => 'Koffi', 'phone' => '+2250102030405', 'status' => 'active', 'pay_rule_id' => $rule->id]);
        $desk = app(OrderDesk::class);
        $missions = app(CourierDesk::class);

        // 2 commandes WhatsApp livrées par Koffi, 1 du site annulée, 1 du site retournée
        foreach ([1, 2] as $quantity) {
            $shipment = app(ShipmentDesk::class)->assignCourier($this->order('whatsapp', $quantity), $courier, [], $this->store->owner);
            foreach (['in_transit', 'out_for_delivery'] as $step) {
                $shipment = $missions->update($shipment, $courier, $step, []);
            }
            $missions->update($shipment, $courier, 'delivered', ['payment_method' => 'cash']);
        }
        $desk->transition($this->order('online_store'), OrderStatus::Cancelled, null, ['reason' => CancelReason::CustomerRequest]);
        $returned = $this->order('online_store');
        $desk->transition($returned, OrderStatus::Shipped, null);
        $desk->transition($returned->fresh(), OrderStatus::Returned, null);

        $stats = $this->storeApi($this->store, 'GET', 'stats')->assertOk()->json('data');
        $fee = DeliveryZone::forStore($this->store->id)->sole()->fee;
        $overview = $stats['overview'];
        $this->assertSame([4, 1, 2, 1, 45000 + 2 * $fee], [$overview['orders'], $overview['cancelled'], $overview['delivered'], $overview['returned'], $overview['revenue']]);
        $this->assertEquals([66.7, 100], [$overview['delivery_rate'], $overview['confirmation_rate']]);
        // Marge : (45 000 − 27 000) + frais facturés − paie du livreur (2 × 1 000)
        $this->assertSame([18000, 2000, 18000 + 2 * $fee - 2000], [$overview['product_margin'], $overview['shipping_costs'], $overview['net_margin']]);

        $sources = collect($stats['by_source'])->keyBy('source');
        $this->assertEquals([2, 100], [$sources['whatsapp']['delivered'], $sources['whatsapp']['delivery_rate']]);
        $this->assertEquals([2, 0], [$sources['online_store']['orders'], $sources['online_store']['delivery_rate']]);
        $this->assertSame(['Robe wax', 3, 45000, 18000], [$stats['by_product'][0]['title'], $stats['by_product'][0]['quantity'], $stats['by_product'][0]['revenue'], $stats['by_product'][0]['margin']]);
        $this->assertEquals(['Koffi', 2, 100, 1000], [$stats['by_courier'][0]['name'], $stats['by_courier'][0]['delivered'], $stats['by_courier'][0]['success_rate'], $stats['by_courier'][0]['avg_cost']]);
        $this->assertSame(4, $stats['by_zone'][0]['orders']);

        $this->storeApi($this->store, 'GET', 'stats?from=2026-01-01&to=2025-01-01')->assertStatus(422);
    }

    public function test_daily_report_by_email_and_whatsapp(): void
    {
        Notification::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $this->order();

        $this->storeApi($this->store, 'PUT', 'reports/schedule', ['channels' => ['email' => ['is_active' => true]]])->assertStatus(422);
        $this->store->owner->forceFill(['email' => 'awa@example.com'])->save();
        $schedule = $this->storeApi($this->store, 'PUT', 'reports/schedule', ['channels' => ['email' => ['is_active' => true, 'send_at' => '00:00'], 'whatsapp' => ['is_active' => true, 'send_at' => '00:00']]])
            ->assertOk()->assertJsonPath('data.channels.email.is_active', true)->assertJsonPath('data.whatsapp_ready', false);
        $this->assertStringContainsString('Commandes reçues : 1', $schedule->json('data.preview'));

        WhatsappAccount::create(['store_id' => $this->store->id, 'phone_number_id' => '123', 'access_token' => 'tok', 'app_secret' => 's', 'verify_token' => Str::random(20),
            'webhook_uuid' => (string) Str::uuid(), 'templates' => ['daily_report' => 'rapport_du_jour'], 'automations' => ['daily_report' => true], 'status' => 'active']);

        $this->artisan('reports:send')->expectsOutput('2 rapport(s) envoyé(s)')->assertSuccessful();
        Notification::assertSentTo($this->store->owner, DailyReportMail::class, fn ($n) => str_contains($n->text, 'Commandes reçues : 1'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/123/messages') && $r['template']['name'] === 'rapport_du_jour' && $r['template']['components'][0]['parameters'][0]['text'] === 'Awa Mode');

        // Déjà envoyé aujourd'hui
        $this->artisan('reports:send')->expectsOutput('0 rapport(s) envoyé(s)');
        $this->assertSame(2, ScheduledReport::whereNotNull('last_sent_at')->count());
    }
}
