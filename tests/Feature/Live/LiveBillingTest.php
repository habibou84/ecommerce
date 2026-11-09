<?php

namespace Tests\Feature\Live;

use App\Exceptions\BusinessRuleException;
use App\Models\LiveItem;
use App\Models\LiveSession;
use App\Models\Place;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Live\LiveBilling;
use App\Services\Live\LiveSales;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\TestCase;

/**
 * Lives payés par palier de commandes, à part des abonnements : commandes offertes au départ (actions IA au 1er
 * live), puis le prix du palier atteint retiré du crédit live à la fin ; recharge et 30 jours par PayDunya ;
 * réglages et cadeaux depuis la console.
 */
class LiveBillingTest extends TestCase
{
    use LiveProductsHavePhotos;

    private const CONSOLE = 'http://admin.localhost';

    private Store $store;

    private int $variant;

    private int $phone = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giveProductsPhotos();
        Http::preventStrayRequests();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $product = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 500]]]);
        $this->variant = $product->variants()->value('id');
        $this->actingAs($this->store->owner);
    }

    private function newLive(): int
    {
        LiveSession::forStore($this->store->id)->where('status', 'live')->update(['status' => 'ended', 'ended_at' => now()]);

        return $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live', 'hold_minutes' => 30, 'items' => [['variant_id' => $this->variant, 'quantity' => 300]]])
            ->assertCreated()->json('data.id');
    }

    private function start(int $id): TestResponse
    {
        return $this->storeApi($this->store, 'POST', "lives/{$id}/start");
    }

    /** Des commandes créées pendant le live (quartier donné : la commande se crée tout de suite) */
    private function orders(int $live, int $count): void
    {
        $item = LiveItem::where('live_session_id', $live)->firstOrFail();
        $place = Place::whereNull('store_id')->where('name', 'Yopougon')->firstOrFail();
        for ($i = 0; $i < $count; $i++) {
            app(LiveSales::class)->reserve($item, ['name' => 'Cliente', 'phone' => '+22507'.str_pad((string) ++$this->phone, 8, '0', STR_PAD_LEFT), 'place_id' => $place->id], 'page');
        }
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    public function test_lives_stay_free_until_paid_lives_are_switched_on(): void
    {
        $live = $this->newLive();
        $this->start($live)->assertOk()->assertJsonPath('data.status', 'live')->assertJsonPath('data.billing', null);
        $this->orders($live, 12);
        $this->storeApi($this->store, 'POST', "lives/{$live}/end")->assertOk();
        $this->assertSame(['free_mode', 12, 0], [LiveSession::find($live)->billing, LiveSession::find($live)->billed_orders, LiveSession::find($live)->billed_amount]);
        $this->assertSame(0, $this->store->fresh()->live_credit);
        $this->storeApi($this->store, 'GET', 'lives/billing')->assertOk()->assertJsonPath('data.paid', false)->assertJsonPath('data.next', 'free_mode');
    }

    public function test_orders_stay_open_24h_after_the_live_and_count_in_the_same_live(): void
    {
        $billing = app(LiveBilling::class);
        $billing->update(['paid' => true, 'free_orders' => 0]);
        $this->store->forceFill(['live_credit' => 3000])->save();
        $live = $this->newLive();
        $this->start($live)->assertOk();
        $this->orders($live, 8);

        // « Terminer le live » en gardant les commandes actives 24 h : rien n'est encore compté
        $this->storeApi($this->store, 'POST', "lives/{$live}/end", ['keep_hours' => 12])->assertStatus(422);
        $this->storeApi($this->store, 'POST', "lives/{$live}/end", ['keep_hours' => 24])->assertOk()
            ->assertJsonPath('data.status', 'ended')->assertJsonPath('data.orders_after', 0)
            ->assertJsonPath('data.orders_until', fn ($at) => abs(now()->addDay()->diffInMinutes($at)) < 2);
        $this->assertNull(LiveSession::find($live)->billed_at);
        $this->assertSame(3000, $this->store->fresh()->live_credit);
        $this->assertSame($live, LiveSales::ordering($this->store->id)?->id);
        $this->assertNull(LiveSales::current($this->store->id));

        // Le lendemain matin, les codes marchent encore : commandes comptées dans ce live (palier 11 à 30)
        $this->travel(10)->hours();
        $this->orders($live, 5);
        $this->storeApi($this->store, 'GET', "lives/{$live}")->assertOk()
            ->assertJsonPath('data.orders_after', 5)->assertJsonPath('data.billing.orders', 13)->assertJsonPath('data.billing.price', 500);

        // À l'heure dite, les commandes s'arrêtent et le live est compté une fois
        $this->travel(15)->hours();
        $this->assertNull(LiveSales::ordering($this->store->id));
        $this->expectsReserveRefused($live);
        $this->artisan('live:expire')->assertSuccessful();
        $this->assertSame([13, 500], [LiveSession::find($live)->billed_orders, LiveSession::find($live)->billed_amount]);
        $this->assertSame(2500, $this->store->fresh()->live_credit);
    }

    public function test_stopping_orders_early_or_starting_a_new_live_counts_the_live(): void
    {
        app(LiveBilling::class)->update(['paid' => true, 'free_orders' => 0]);
        $this->store->forceFill(['live_credit' => 5000])->save();
        $live = $this->newLive();
        $this->start($live)->assertOk();
        $this->orders($live, 12);
        $this->storeApi($this->store, 'POST', "lives/{$live}/end", ['keep_hours' => 48])->assertOk();
        $this->storeApi($this->store, 'POST', "lives/{$live}/stop-orders")->assertOk()
            ->assertJsonPath('data.orders_until', null)->assertJsonPath('data.billing.billed', true)->assertJsonPath('data.billing.price', 500);
        $this->expectsReserveRefused($live);

        // Commandes encore actives quand un nouveau live commence : l'ancien s'arrête et il est compté
        $second = $this->newLive();
        $this->start($second)->assertOk();
        $this->orders($second, 3);
        $this->storeApi($this->store, 'POST', "lives/{$second}/end", ['keep_hours' => 24])->assertOk();
        $third = $this->newLive();
        $this->start($third)->assertOk();
        $this->assertNotNull(LiveSession::find($second)->billed_at);
        $this->assertSame($third, LiveSales::ordering($this->store->id)->id);
    }

    private function expectsReserveRefused(int $live): void
    {
        try {
            $this->orders($live, 1);
            $this->fail('Réservation acceptée alors que les commandes sont arrêtées.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('pas en cours', $e->getMessage());
        }
    }

    public function test_tier_prices_and_every_created_order_counts(): void
    {
        $billing = app(LiveBilling::class);
        $this->assertSame([0, 0, 500, 500, 1000, 1000, 2000], array_map(fn ($n) => $billing->priceFor($n), [0, 10, 11, 30, 31, 100, 101]));

        $billing->update(['paid' => true, 'free_orders' => 0]);
        $this->store->forceFill(['live_credit' => 3000])->save();
        $live = $this->newLive();
        $this->start($live)->assertOk();
        $this->orders($live, 23);

        // Pendant le live : palier 2 (500 F), 8 commandes avant le palier 3
        $this->storeApi($this->store, 'GET', "lives/{$live}")->assertOk()
            ->assertJsonPath('data.billing.orders', 23)->assertJsonPath('data.billing.tier', 2)->assertJsonPath('data.billing.price', 500)
            ->assertJsonPath('data.billing.next.in', 8)->assertJsonPath('data.billing.next.price', 1000);

        // Une commande annulée reste comptée : le prix est connu dès la fin du live
        LiveSession::find($live)->reservations()->whereNotNull('order_id')->first()->order->update(['status' => 'cancelled']);
        // … mais pas une fausse commande (annulée pour faux numéro) : de fausses réservations ne gonflent pas la facture
        LiveSession::find($live)->reservations()->whereNotNull('order_id')->latest('id')->first()->order->forceFill(['status' => 'cancelled', 'cancel_reason' => 'wrong_number'])->save();
        $this->storeApi($this->store, 'POST', "lives/{$live}/end")->assertOk()->assertJsonPath('data.billing.billed', true)->assertJsonPath('data.billing.price', 500);
        $this->assertSame([22, 500], [LiveSession::find($live)->billed_orders, LiveSession::find($live)->billed_amount]);
        $this->assertSame(2500, $this->store->fresh()->live_credit);

        // Une seule fois par live
        app(LiveBilling::class)->bill(LiveSession::find($live));
        $this->assertSame(2500, $this->store->fresh()->live_credit);
    }

    public function test_offered_orders_first_then_credit_and_recharge_by_paydunya(): void
    {
        app(LiveBilling::class)->update(['paid' => true, 'free_orders' => 30, 'free_ai' => 20]);
        config(['platform.billing.credentials' => ['master_key' => 'mk', 'private_key' => 'pk', 'token' => 'tk'], 'platform.billing.mode' => 'test']);
        $credits = (int) $this->store->fresh()->ai_credits;
        $this->storeApi($this->store, 'GET', 'lives/billing')->assertOk()->assertJsonPath('data.next', 'ok')->assertJsonPath('data.free_left', 30)
            ->assertJsonPath('data.credit', 0)->assertJsonPath('data.min_credit', 500);

        // 1er live : 25 commandes, toutes offertes ; les actions IA offertes une fois
        $first = $this->newLive();
        $this->start($first)->assertOk();
        $this->assertSame($credits + 20, (int) $this->store->fresh()->ai_credits);
        $this->orders($first, 25);
        $this->storeApi($this->store, 'POST', "lives/{$first}/end")->assertOk();
        $this->assertSame([0, 25, 0], [LiveSession::find($first)->billed_amount, $this->store->fresh()->live_orders_used, $this->store->fresh()->live_credit]);

        // 2e live : 5 offertes restantes, 20 commandes → 15 payantes, palier 2 ; crédit à 0 : 500 F de dette
        $second = $this->newLive();
        $this->start($second)->assertOk();
        $this->orders($second, 20);
        // Pendant le live : le crédit (0 F) ne couvre pas le palier atteint, il manquera 500 F
        $this->storeApi($this->store, 'GET', "lives/{$second}")->assertJsonPath('data.billing.price', 500)->assertJsonPath('data.billing.shortfall', 500);
        // Bilan : crédit à −500 F, recharger au moins 1 000 F pour ouvrir le live suivant
        $this->storeApi($this->store, 'POST', "lives/{$second}/end")->assertOk()
            ->assertJsonPath('data.billing.credit', -500)->assertJsonPath('data.billing.recharge', 1000)->assertJsonPath('data.billing.shortfall', 0);
        $this->assertSame([500, 30, -500], [LiveSession::find($second)->billed_amount, $this->store->fresh()->live_orders_used, $this->store->fresh()->live_credit]);

        // Plus rien d'offert et crédit insuffisant : le live ne s'ouvre pas, la recharge est demandée
        $third = $this->newLive();
        $this->start($third)->assertStatus(422)->assertJsonValidationErrors('payment');
        $this->assertSame('draft', LiveSession::find($third)->status);
        $this->storeApi($this->store, 'GET', 'lives/billing')->assertJsonPath('data.next', 'pay');

        // Recharge de 5 000 F par PayDunya (prestataire des lives, même si les abonnements passent par Wave)
        Http::fake([
            'https://app.paydunya.com/sandbox-api/v1/checkout-invoice/create' => Http::response(['response_code' => '00', 'response_text' => 'https://app.paydunya.com/sandbox-checkout/invoice/live_1', 'token' => 'live_1']),
            'https://app.paydunya.com/sandbox-api/v1/checkout-invoice/confirm/live_1' => Http::response(['response_code' => '00', 'status' => 'completed', 'invoice' => ['total_amount' => 5000]]),
        ]);
        $this->storeApi($this->store, 'POST', 'lives/billing/checkout', ['kind' => 'live_credit', 'amount' => 200])->assertStatus(422);
        $pay = $this->storeApi($this->store, 'POST', 'lives/billing/checkout', ['kind' => 'live_credit', 'amount' => 5000])->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://app.paydunya.com/sandbox-checkout/invoice/live_1');
        $invoice = SubscriptionInvoice::where('number', $pay->json('data.number'))->sole();
        $this->assertSame(['paydunya', 5000, 'pending', 'live_credit'], [$invoice->provider, $invoice->amount, $invoice->status, $invoice->kind]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'checkout-invoice/create') && str_contains((string) ($r['actions']['return_url'] ?? ''), '/gestion/live?paiement='));

        $this->storeApi($this->store, 'POST', "lives/billing/{$invoice->number}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.credit', 4500)->assertJsonPath('data.next', 'ok');
        $this->start($third)->assertOk()->assertJsonPath('data.status', 'live');

        // Les paiements des lives restent à part des factures de l'abonnement
        $this->assertNotContains($invoice->number, collect($this->storeApi($this->store, 'GET', 'billing')->json('data.invoices'))->pluck('number')->all());
        $this->assertSame([$invoice->number], collect($this->storeApi($this->store, 'GET', 'lives/billing')->json('data.payments'))->pluck('number')->all());
    }

    public function test_unlimited_month_and_lives_left_open_are_closed_and_counted(): void
    {
        $billing = app(LiveBilling::class);
        $billing->update(['paid' => true, 'free_orders' => 0]);
        $this->store->forceFill(['live_month_until' => now()->addDays(10)])->save();
        $live = $this->newLive();
        $this->start($live)->assertOk();
        $this->orders($live, 40);
        $this->storeApi($this->store, 'POST', "lives/{$live}/end")->assertOk();
        $this->assertSame(['month', 0, 0], [LiveSession::find($live)->billing, LiveSession::find($live)->billed_amount, $this->store->fresh()->live_credit]);

        // Live oublié ouvert : fermé et compté au bout de 12 h
        $this->store->forceFill(['live_month_until' => null, 'live_credit' => 1000])->save();
        $open = $this->newLive();
        $this->start($open)->assertOk();
        $this->orders($open, 12);
        $this->travel(13)->hours();
        $this->assertSame(1, $billing->closeStale());
        $this->assertSame(['ended', 500], [LiveSession::find($open)->status, LiveSession::find($open)->billed_amount]);
        $this->assertSame(500, $this->store->fresh()->live_credit);
    }

    public function test_the_console_sets_tiers_and_offers_orders_credit_or_a_month(): void
    {
        $admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->logout();
        $this->signInConsole('07 01 02 03 04');

        $this->console('GET', 'lives')->assertOk()->assertJsonPath('data.paid', false)->assertJsonPath('data.free_orders', 300)
            ->assertJsonPath('data.min_credit', 500)->assertJsonPath('data.tier2_price', 500)->assertJsonPath('data.month_price', 7000);
        $this->console('PUT', 'lives', ['paid' => true, 'tier2_price' => 600, 'free_orders' => 100])->assertOk()
            ->assertJsonPath('data.paid', true)->assertJsonPath('data.tier2_price', 600)->assertJsonPath('data.tiers.1.price', 600);
        // Paliers dans le désordre : refusé
        $this->console('PUT', 'lives', ['tier2_upto' => 5])->assertStatus(422)->assertJsonValidationErrors('tier2_upto');

        $this->console('POST', "stores/{$this->store->id}/lives", ['orders' => 50])->assertOk()->assertJsonPath('data.free_left', 150);
        $this->console('POST', "stores/{$this->store->id}/lives", ['credit' => 2000])->assertOk()->assertJsonPath('data.credit', 2000);
        $this->console('POST', "stores/{$this->store->id}/lives", ['month' => true])->assertOk();
        $this->assertTrue($this->store->fresh()->live_month_until->isAfter(now()->addDays(29)));
        $this->assertSame(['offered', 0], [SubscriptionInvoice::where('kind', 'live_credit')->sole()->status, SubscriptionInvoice::where('kind', 'live_credit')->sole()->amount]);
    }
}
