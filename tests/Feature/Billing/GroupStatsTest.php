<?php

namespace Tests\Feature\Billing;

use App\Enums\OrderStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ScheduledReport;
use App\Models\Store;
use App\Notifications\DailyReportMail;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GroupStatsTest extends TestCase
{
    private Store $mode;

    private Store $kids;

    private int $phone = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mode = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->mode->currentSubscription()->first()->update(['status' => SubscriptionStatus::Expired]);
        $this->mode->subscriptions()->create(['plan_id' => Plan::where('code', 'business')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $this->actingAs($this->mode->owner);
        $this->storeApi($this->mode, 'POST', 'my-stores', ['name' => 'Awa Kids', 'slug' => 'awa-kids'])->assertCreated();
        $this->kids = Store::where('slug', 'awa-kids')->sole();
    }

    private function sell(Store $store, string $title, int $price, int $count, bool $delivered): void
    {
        app(Tenancy::class)->setStore($store);
        app(Places::class)->provisionDefaultZone($store);
        $product = app(CatalogWriter::class)->save($store, null, ['title' => $title, 'variants' => [['price' => $price, 'on_hand' => 50]]]);
        $desk = app(OrderDesk::class);
        foreach (range(1, $count) as $i) {
            $order = $desk->create($store, [
                'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+22505060708'.$this->phone++, 'delivery_method' => 'pickup', 'confirmed' => true,
                'items' => [['variant_id' => $product->variants[0]->id, 'quantity' => 1]],
            ], $store->owner);
            if ($delivered) {
                $desk->transition($order, OrderStatus::Completed, null);
            }
        }
        app(Tenancy::class)->setStore(null);
    }

    public function test_statistics_and_cash_of_all_stores(): void
    {
        $this->sell($this->mode, 'Robe wax', 15000, 2, true);
        $this->sell($this->kids, 'Robe wax', 10000, 1, true);
        $this->sell($this->kids, 'Body bébé', 5000, 1, false);

        $stats = $this->storeApi($this->kids, 'GET', 'my-stores/stats')->assertOk()->json('data');
        $this->assertSame([4, 3, 40000], [$stats['total']['orders'], $stats['total']['delivered'], $stats['total']['revenue']]);
        $this->assertSame(11250, $stats['total']['avg_basket']);
        $this->assertSame([['Awa Mode', 30000], ['Awa Kids', 10000]], array_map(fn ($s) => [$s['name'], $s['overview']['revenue']], $stats['stores']));

        $today = collect($stats['daily'])->last();
        $this->assertSame([4, 40000, [$this->mode->id => 30000, $this->kids->id => 10000]], [$today['orders'], $today['revenue'], $today['by_store']]);
        $this->assertCount(30, $stats['daily']);

        // Un même produit réunit les deux boutiques
        $this->assertSame(['Robe wax', 3, 40000, ['Awa Mode', 'Awa Kids']], array_values($stats['top_products'][0]));

        $cash = $this->storeApi($this->mode, 'GET', 'my-stores/cash?period=month')->assertOk()->json('data');
        $this->assertSame(['Awa Mode', 'Awa Kids'], array_column($cash['stores'], 'name'));
        $this->assertSame(array_sum(array_column($cash['stores'], 'customers_due')), $cash['total']['customers_due']);

        // Sans abonnement commun, ou pour un autre membre de l'équipe : pas de chiffres communs
        $this->actingAs($this->addMember($this->mode));
        $this->storeApi($this->mode, 'GET', 'my-stores/stats')->assertForbidden();
        $this->actingAs($this->mode->owner);
        $this->mode->currentSubscription()->first()->update(['status' => SubscriptionStatus::Expired]);
        $this->storeApi($this->mode, 'GET', 'my-stores/stats')->assertForbidden()->assertJsonPath('upgrade', true);
    }

    public function test_one_evening_report_for_all_stores(): void
    {
        Notification::fake();
        $this->sell($this->mode, 'Robe wax', 15000, 2, true);
        $this->sell($this->kids, 'Body bébé', 5000, 1, false);
        $this->mode->owner->forceFill(['email' => 'awa@example.com'])->save();

        $this->storeApi($this->mode, 'GET', 'reports/schedule')->assertJsonPath('data.group_stores', 2);
        $this->storeApi($this->mode, 'PUT', 'reports/schedule', ['channels' => ['email' => ['is_active' => true, 'send_at' => '00:00', 'all_stores' => true]]])
            ->assertOk()->assertJsonPath('data.channels.email.all_stores', true);
        $this->assertTrue(ScheduledReport::withoutGlobalScopes()->sole()->all_stores);

        $this->artisan('reports:send')->expectsOutput('1 rapport(s) envoyé(s)');
        Notification::assertSentTo($this->mode->owner, DailyReportMail::class, fn ($n) => str_contains($n->text, '2 boutiques')
            && str_contains($n->text, 'Commandes reçues : 3') && str_contains($n->text, '• Awa Kids : 1 commande(s)'));
        $this->assertSame(1, Order::forStore($this->kids->id)->count());
    }
}
