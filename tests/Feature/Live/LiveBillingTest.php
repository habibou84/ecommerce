<?php

namespace Tests\Feature\Live;

use App\Models\LiveSession;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Live\LiveBilling;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\TestCase;

/**
 * Lives payants, à part des abonnements : lives offerts au départ (actions IA au 1er), puis 24 h ou 30 jours payés
 * par PayDunya ; réglages et lives offerts depuis la console.
 */
class LiveBillingTest extends TestCase
{
    use LiveProductsHavePhotos;

    private const CONSOLE = 'http://admin.localhost';

    private Store $store;

    private int $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giveProductsPhotos();
        Http::preventStrayRequests();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $product = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 50]]]);
        $this->variant = $product->variants()->value('id');
        $this->actingAs($this->store->owner);
    }

    private function newLive(): int
    {
        // Un live en préparation à la fois : le précédent est terminé
        LiveSession::forStore($this->store->id)->where('status', 'live')->update(['status' => 'ended', 'ended_at' => now()]);

        return $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live', 'hold_minutes' => 30, 'items' => [['variant_id' => $this->variant, 'quantity' => 3]]])
            ->assertCreated()->json('data.id');
    }

    private function start(int $id): TestResponse
    {
        return $this->storeApi($this->store, 'POST', "lives/{$id}/start");
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    public function test_lives_stay_free_until_paid_lives_are_switched_on(): void
    {
        $this->start($this->newLive())->assertOk()->assertJsonPath('data.status', 'live');
        $this->assertSame('free_mode', LiveSession::sole()->billing);
        $this->assertSame(0, $this->store->fresh()->live_free_used);
        $this->storeApi($this->store, 'GET', 'lives/billing')->assertOk()->assertJsonPath('data.paid', false)->assertJsonPath('data.next', 'free_mode');
    }

    public function test_offered_lives_then_payment_by_paydunya_for_24_hours_or_30_days(): void
    {
        app(LiveBilling::class)->update(['paid' => true, 'free_lives' => 2, 'free_ai' => 20]);
        config(['platform.billing.credentials' => ['master_key' => 'mk', 'private_key' => 'pk', 'token' => 'tk'], 'platform.billing.mode' => 'test']);
        $credits = (int) $this->store->fresh()->ai_credits;

        // 2 lives offerts : le 1er ajoute les 20 actions IA, une seule fois
        $this->storeApi($this->store, 'GET', 'lives/billing')->assertOk()->assertJsonPath('data.next', 'offered')->assertJsonPath('data.free_left', 2)->assertJsonPath('data.free_ai', 20);
        $this->start($this->newLive())->assertOk();
        $this->assertSame($credits + 20, (int) $this->store->fresh()->ai_credits);
        $this->start($this->newLive())->assertOk();
        $this->assertSame([2, $credits + 20], [$this->store->fresh()->live_free_used, (int) $this->store->fresh()->ai_credits]);

        // Plus rien d'offert : le live ne démarre pas, le paiement est demandé
        $third = $this->newLive();
        $this->start($third)->assertStatus(422)->assertJsonValidationErrors('payment');
        $this->assertSame('draft', LiveSession::find($third)->status);
        $this->storeApi($this->store, 'GET', 'lives/billing')->assertJsonPath('data.next', 'pay')->assertJsonPath('data.prices.day', 500)->assertJsonPath('data.prices.month', 7000);

        // 24 h payées par PayDunya (prestataire des lives, même si les abonnements passent par Wave)
        Http::fake([
            'https://app.paydunya.com/sandbox-api/v1/checkout-invoice/create' => Http::response(['response_code' => '00', 'response_text' => 'https://app.paydunya.com/sandbox-checkout/invoice/live_1', 'token' => 'live_1']),
            'https://app.paydunya.com/sandbox-api/v1/checkout-invoice/confirm/live_1' => Http::response(['response_code' => '00', 'status' => 'completed', 'invoice' => ['total_amount' => 500]]),
        ]);
        $pay = $this->storeApi($this->store, 'POST', 'lives/billing/checkout', ['kind' => 'live_day'])->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://app.paydunya.com/sandbox-checkout/invoice/live_1');
        $invoice = SubscriptionInvoice::where('number', $pay->json('data.number'))->sole();
        $this->assertSame(['paydunya', 500, 'pending'], [$invoice->provider, $invoice->amount, $invoice->status]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'checkout-invoice/create') && str_contains((string) ($r['actions']['return_url'] ?? ''), '/gestion/live?paiement='));

        // Retour de PayDunya : statut relu, 24 h de lives, le live démarre
        $this->storeApi($this->store, 'POST', "lives/billing/{$invoice->number}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.next', 'covered');
        $this->assertTrue($this->store->fresh()->live_pass_until->between(now()->addHours(23), now()->addHours(25)));
        $this->start($third)->assertOk()->assertJsonPath('data.status', 'live');
        $this->assertSame('day', LiveSession::find($third)->billing);
        // Autant de lives qu'on veut pendant les 24 h
        $this->start($this->newLive())->assertOk();

        // Le paiement des lives n'apparaît pas dans les factures de l'abonnement, mais dans ceux des lives
        $this->assertNotContains($invoice->number, collect($this->storeApi($this->store, 'GET', 'billing')->json('data.invoices'))->pluck('number')->all());
        $this->assertSame([$invoice->number], collect($this->storeApi($this->store, 'GET', 'lives/billing')->json('data.payments'))->pluck('number')->all());

        // Après les 24 h : on paie de nouveau
        $this->travel(25)->hours();
        $this->start($this->newLive())->assertStatus(422)->assertJsonValidationErrors('payment');
    }

    public function test_the_console_sets_prices_and_offers_lives(): void
    {
        $admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->logout();
        $login = $this->console('POST', 'auth/login', ['phone' => '07 01 02 03 04', 'password' => 'motdepasse', 'channel' => 'phone'])->assertStatus(202);
        $this->console('POST', 'auth/verify', ['token' => $login->json('verification.token'), 'code' => $login->json('demo_code')])->assertOk();

        $this->console('GET', 'lives')->assertOk()->assertJsonPath('data.paid', false)->assertJsonPath('data.free_lives', 10)
            ->assertJsonPath('data.day_price', 500)->assertJsonPath('data.month_price', 7000);
        $this->console('PUT', 'lives', ['paid' => true, 'day_price' => 600, 'free_lives' => 0])->assertOk()
            ->assertJsonPath('data.paid', true)->assertJsonPath('data.day_price', 600)->assertJsonPath('data.free_lives', 0);

        // Lives offerts à une boutique, puis 30 jours illimités
        $this->console('POST', "stores/{$this->store->id}/lives", ['lives' => 3])->assertOk()->assertJsonPath('data.free_left', 3);
        $this->console('POST', "stores/{$this->store->id}/lives", ['month' => true])->assertOk();
        $this->assertTrue($this->store->fresh()->live_month_until->isAfter(now()->addDays(29)));
        $this->assertSame('offered', SubscriptionInvoice::where('kind', 'live_month')->sole()->status);
    }
}
