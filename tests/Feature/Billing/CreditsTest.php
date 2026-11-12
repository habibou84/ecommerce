<?php

namespace Tests\Feature\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\LiveItem;
use App\Models\LiveSession;
use App\Models\Place;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Billing\Credits;
use App\Services\Catalog\CatalogWriter;
use App\Services\Live\LiveSales;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\TestCase;

/**
 * Solde unique : commandes (live, WhatsApp, boost) et actions IA achetées ensemble. Commandes et IA offertes au
 * départ, commandes d'un live retirées à la fin, pack ou illimité 30 jours par PayDunya, réglages et cadeaux depuis
 * la console › Tarifs.
 */
class CreditsTest extends TestCase
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

    public function test_nothing_is_counted_until_paid_orders_are_switched_on(): void
    {
        $live = $this->newLive();
        $this->start($live)->assertOk()->assertJsonPath('data.status', 'live')->assertJsonPath('data.billing', null);
        $this->orders($live, 12);
        $this->storeApi($this->store, 'POST', "lives/{$live}/end")->assertOk();
        $this->assertSame(['free_mode', 12, 0], [LiveSession::find($live)->billing, LiveSession::find($live)->billed_orders, LiveSession::find($live)->billed_amount]);
        $this->assertSame(0, $this->store->fresh()->order_credits);
        $this->storeApi($this->store, 'GET', 'credits')->assertOk()->assertJsonPath('data.paid', false)->assertJsonPath('data.next', 'free_mode');
    }

    public function test_orders_stay_open_24h_after_the_live_and_count_in_the_same_live(): void
    {
        app(Credits::class)->update(['paid' => true]);
        $this->store->forceFill(['order_credits' => 100, 'credits_welcomed_at' => now()])->save();
        $live = $this->newLive();
        $this->start($live)->assertOk();
        $this->orders($live, 8);

        // « Terminer le live » en gardant les commandes actives 24 h : rien n'est encore retiré
        $this->storeApi($this->store, 'POST', "lives/{$live}/end", ['keep_hours' => 12])->assertStatus(422);
        $this->storeApi($this->store, 'POST', "lives/{$live}/end", ['keep_hours' => 24])->assertOk()
            ->assertJsonPath('data.status', 'ended')->assertJsonPath('data.orders_after', 0)
            ->assertJsonPath('data.orders_until', fn ($at) => abs(now()->addDay()->diffInMinutes($at)) < 2);
        $this->assertNull(LiveSession::find($live)->billed_at);
        $this->assertSame(100, $this->store->fresh()->order_credits);
        $this->assertSame($live, LiveSales::ordering($this->store->id)?->id);
        $this->assertNull(LiveSales::current($this->store->id));

        // Le lendemain matin, les codes marchent encore : commandes comptées dans ce live
        $this->travel(10)->hours();
        $this->orders($live, 5);
        $this->storeApi($this->store, 'GET', "lives/{$live}")->assertOk()
            ->assertJsonPath('data.orders_after', 5)->assertJsonPath('data.billing.orders', 13)->assertJsonPath('data.billing.after', 87);

        // À l'heure dite, les commandes s'arrêtent et le live est compté une fois
        $this->travel(15)->hours();
        $this->assertNull(LiveSales::ordering($this->store->id));
        $this->expectsReserveRefused($live);
        $this->artisan('live:expire')->assertSuccessful();
        $this->assertSame([13, 13], [LiveSession::find($live)->billed_orders, LiveSession::find($live)->billed_amount]);
        $this->assertSame(87, $this->store->fresh()->order_credits);
    }

    public function test_stopping_orders_early_or_starting_a_new_live_counts_the_live(): void
    {
        app(Credits::class)->update(['paid' => true]);
        $this->store->forceFill(['order_credits' => 100, 'credits_welcomed_at' => now()])->save();
        $live = $this->newLive();
        $this->start($live)->assertOk();
        $this->orders($live, 12);
        $this->storeApi($this->store, 'POST', "lives/{$live}/end", ['keep_hours' => 48])->assertOk();
        $this->storeApi($this->store, 'POST', "lives/{$live}/stop-orders")->assertOk()
            ->assertJsonPath('data.orders_until', null)->assertJsonPath('data.billing.billed', true)->assertJsonPath('data.billing.used', 12);
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

    public function test_every_created_order_counts_once_except_fake_ones(): void
    {
        app(Credits::class)->update(['paid' => true]);
        $this->store->forceFill(['order_credits' => 30, 'credits_welcomed_at' => now()])->save();
        $live = $this->newLive();
        $this->start($live)->assertOk();
        $this->orders($live, 23);

        // Pendant le live : 23 commandes, solde après le live 7
        $this->storeApi($this->store, 'GET', "lives/{$live}")->assertOk()
            ->assertJsonPath('data.billing.orders', 23)->assertJsonPath('data.billing.balance', 30)->assertJsonPath('data.billing.after', 7);

        // Une commande annulée reste comptée, mais pas une fausse (annulée pour faux numéro)
        LiveSession::find($live)->reservations()->whereNotNull('order_id')->first()->order->update(['status' => 'cancelled']);
        LiveSession::find($live)->reservations()->whereNotNull('order_id')->latest('id')->first()->order->forceFill(['status' => 'cancelled', 'cancel_reason' => 'wrong_number'])->save();
        $this->storeApi($this->store, 'POST', "lives/{$live}/end")->assertOk()->assertJsonPath('data.billing.billed', true)
            ->assertJsonPath('data.billing.used', 22)->assertJsonPath('data.billing.recharge', true);
        $this->assertSame(8, $this->store->fresh()->order_credits);

        // Une seule fois par live
        app(Credits::class)->bill(LiveSession::find($live));
        $this->assertSame(8, $this->store->fresh()->order_credits);
    }

    public function test_welcome_gift_then_pack_by_paydunya_with_ai_included(): void
    {
        app(Credits::class)->update(['paid' => true, 'welcome_orders' => 30, 'welcome_ai' => 20, 'min_orders' => 10]);
        config(['platform.billing.credentials' => ['master_key' => 'mk', 'private_key' => 'pk', 'token' => 'tk'], 'platform.billing.mode' => 'test']);
        $ai = (int) $this->store->fresh()->ai_credits;

        // Offert au départ, une seule fois
        $this->storeApi($this->store, 'GET', 'credits')->assertOk()->assertJsonPath('data.next', 'ok')->assertJsonPath('data.orders', 30)
            ->assertJsonPath('data.ai', $ai + 20)->assertJsonPath('data.packs.1.orders', 300)->assertJsonPath('data.packs.1.unit', 20)
            ->assertJsonPath('data.month.price', 7000);
        $this->storeApi($this->store, 'GET', 'credits')->assertJsonPath('data.orders', 30);

        // 25 commandes en live : il en reste 5, sous le seuil d'alerte ; sous le minimum, le live suivant ne s'ouvre pas
        $first = $this->newLive();
        $this->start($first)->assertOk();
        $this->orders($first, 25);
        $this->storeApi($this->store, 'POST', "lives/{$first}/end")->assertOk();
        $this->storeApi($this->store, 'GET', 'credits')->assertJsonPath('data.orders', 5)->assertJsonPath('data.low', true)->assertJsonPath('data.next', 'pay')
            ->assertJsonPath('data.usage.lives.0.orders', -25);
        $second = $this->newLive();
        $this->start($second)->assertStatus(422)->assertJsonValidationErrors('payment');
        $this->assertSame('draft', LiveSession::find($second)->status);

        // Pack de 300 commandes (6 000 F) par PayDunya : commandes et IA ajoutées ensemble
        Http::fake([
            'https://app.paydunya.com/sandbox-api/v1/checkout-invoice/create' => Http::response(['response_code' => '00', 'response_text' => 'https://app.paydunya.com/sandbox-checkout/invoice/pack_1', 'token' => 'pack_1']),
            'https://app.paydunya.com/sandbox-api/v1/checkout-invoice/confirm/pack_1' => Http::response(['response_code' => '00', 'status' => 'completed', 'invoice' => ['total_amount' => 6000]]),
        ]);
        $this->storeApi($this->store, 'POST', 'credits/checkout', ['kind' => 'pack', 'pack' => 7])->assertStatus(422);
        $pay = $this->storeApi($this->store, 'POST', 'credits/checkout', ['kind' => 'pack', 'pack' => 2])->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://app.paydunya.com/sandbox-checkout/invoice/pack_1');
        $invoice = SubscriptionInvoice::where('number', $pay->json('data.number'))->sole();
        $this->assertSame(['paydunya', 6000, 'pending', 'credits_pack', 300, 300], [$invoice->provider, $invoice->amount, $invoice->status, $invoice->kind, $invoice->credit_orders, $invoice->ai_actions]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'checkout-invoice/create') && str_contains((string) ($r['actions']['return_url'] ?? ''), '/gestion/solde?paiement='));

        $this->storeApi($this->store, 'POST', "credits/{$invoice->number}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.orders', 305)->assertJsonPath('data.ai', $ai + 320)->assertJsonPath('data.next', 'ok')
            ->assertJsonPath('data.payments.0.label', 'Pack de 300 commandes + 300 IA');
        $this->start($second)->assertOk()->assertJsonPath('data.status', 'live');

        // Les recharges restent à part des factures de l'abonnement
        $this->assertNotContains($invoice->number, collect($this->storeApi($this->store, 'GET', 'billing')->json('data.invoices'))->pluck('number')->all());
    }

    public function test_unlimited_month_and_lives_left_open_are_closed_and_counted(): void
    {
        $credits = app(Credits::class);
        $credits->update(['paid' => true]);
        $this->store->forceFill(['live_month_until' => now()->addDays(10), 'order_credits' => 0, 'credits_welcomed_at' => now()])->save();
        $live = $this->newLive();
        $this->start($live)->assertOk();
        $this->orders($live, 40);
        $this->storeApi($this->store, 'POST', "lives/{$live}/end")->assertOk();
        $this->assertSame(['month', 0, 0], [LiveSession::find($live)->billing, LiveSession::find($live)->billed_amount, $this->store->fresh()->order_credits]);
        $this->storeApi($this->store, 'GET', 'credits')->assertJsonPath('data.next', 'covered')->assertJsonPath('data.low', false);

        // Live oublié ouvert : fermé et compté au bout de 12 h
        $this->store->forceFill(['live_month_until' => null, 'order_credits' => 20])->save();
        $open = $this->newLive();
        $this->start($open)->assertOk();
        $this->orders($open, 12);
        $this->travel(13)->hours();
        $this->assertSame(1, $credits->closeStale());
        $this->assertSame(['ended', 12], [LiveSession::find($open)->status, LiveSession::find($open)->billed_amount]);
        $this->assertSame(8, $this->store->fresh()->order_credits);
    }

    public function test_the_console_sets_prices_and_offers_orders_ai_or_a_month(): void
    {
        $admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->logout();
        $this->signInConsole('07 01 02 03 04');

        $this->console('GET', 'tarifs')->assertOk()->assertJsonPath('data.paid', false)->assertJsonPath('data.welcome_orders', 50)
            ->assertJsonPath('data.pack1_price', 2500)->assertJsonPath('data.month_price', 7000)->assertJsonPath('data.boost_weight', 1);
        $this->console('PUT', 'tarifs', ['paid' => true, 'pack1_price' => 3000, 'boost_weight' => 2])->assertOk()
            ->assertJsonPath('data.paid', true)->assertJsonPath('data.packs.0.price', 3000)->assertJsonPath('data.packs.0.unit', 30);
        // Packs dans le désordre : refusé
        $this->console('PUT', 'tarifs', ['pack2_orders' => 50])->assertStatus(422)->assertJsonValidationErrors('pack2_orders');

        $this->console('POST', "stores/{$this->store->id}/credits", ['orders' => 40, 'ai' => 10])->assertOk()->assertJsonPath('data.orders', 90);
        $this->console('POST', "stores/{$this->store->id}/credits", ['month' => true])->assertOk();
        $this->assertTrue($this->store->fresh()->live_month_until->isAfter(now()->addDays(29)));
        $this->assertSame(['offered', 0, 40], [SubscriptionInvoice::where('kind', 'credits_pack')->sole()->status, SubscriptionInvoice::where('kind', 'credits_pack')->sole()->amount, SubscriptionInvoice::where('kind', 'credits_pack')->sole()->credit_orders]);
        $this->console('GET', "stores/{$this->store->id}")->assertJsonPath('data.credits.orders', 90);
    }
}
