<?php

namespace Tests\Feature\Billing;

use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Place;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Notifications\BillingNotice;
use App\Services\Billing\Billing;
use App\Services\Billing\PlanLimits;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use App\Services\Storefront\CartService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingTest extends TestCase
{
    private Store $store;

    private string $waveStatus = 'processing';

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.billing' => ['provider' => 'wave', 'mode' => 'live', 'credentials' => ['api_key' => 'wave_platform', 'webhook_secret' => 'platform_secret'], 'grace_days' => 7, 'reminder_days' => 3]]);
        Http::preventStrayRequests();
        Http::fake(['api.wave.com/v1/checkout/sessions*' => fn (HttpRequest $r) => Http::response([
            'id' => 'cos-plateforme', 'amount' => $r->method() === 'POST' ? $r['amount'] : '15000', 'currency' => 'XOF',
            'payment_status' => $this->waveStatus, 'checkout_status' => 'open', 'wave_launch_url' => 'https://pay.wave.com/c/cos-plateforme',
        ])]);
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode', 'status' => StoreStatus::Trial]);
        $this->actingAs($this->store->owner);
    }

    public function test_paying_a_plan_with_the_platform_account(): void
    {
        $overview = $this->storeApi($this->store, 'GET', 'billing')->assertOk();
        $overview->assertJsonPath('data.plan.code', 'pro')->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.methods.wave', 'Wave')->assertJsonPath('data.usage.products.used', 0);

        $checkout = $this->storeApi($this->store, 'POST', 'billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly', 'method' => 'wave'])
            ->assertCreated()->assertJsonPath('redirect', 'https://pay.wave.com/c/cos-plateforme');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer wave_platform') && $r['amount'] === '15000'
            && str_contains($r['success_url'], '/gestion/abonnement?facture=JM-'));
        $number = $checkout->json('data.number');
        $this->assertMatchesRegularExpression('/^JM-\d{4}-\d{6}$/', $number);

        // Retour sans paiement confirmé : rien ne change
        $this->storeApi($this->store, 'POST', "billing/invoices/{$number}/check")->assertJsonPath('data.status', 'pending');

        // Notification signée du prestataire, statut relu : offre Pro payée pour un mois
        $this->waveStatus = 'succeeded';
        $body = json_encode(['id' => 'EV_1', 'type' => 'checkout.session.completed', 'data' => ['id' => 'cos-plateforme', 'client_reference' => SubscriptionInvoice::first()->idempotency_key]]);
        $t = (string) time();
        $this->call('POST', 'http://api.localhost/webhooks/billing', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WAVE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.$body, 'platform_secret')], $body)->assertOk();
        $this->call('POST', 'http://api.localhost/webhooks/billing', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WAVE_SIGNATURE' => "t={$t},v1=bad"], $body)->assertStatus(401);

        $invoice = SubscriptionInvoice::first();
        $this->assertSame(['paid', 'wave'], [$invoice->status, $invoice->method]);
        $sub = $this->store->currentSubscription()->first();
        $this->assertSame([SubscriptionStatus::Active, 'pro'], [$sub->status, $sub->plan->code]);
        $this->assertTrue($sub->current_period_end->between(now()->addMonth()->subMinute(), now()->addMonth()->addMinute()));
        $this->assertSame(StoreStatus::Active, $this->store->fresh()->status);
        $this->assertSame(SubscriptionStatus::Cancelled, Subscription::where('status', 'cancelled')->sole()->status);

        $this->storeApi($this->store, 'GET', 'billing')->assertJsonPath('data.invoices.0.status', 'paid');
        $pdf = $this->withCredentials()->get($this->storeUrl($this->store, "/api/v1/billing/invoices/{$invoice->id}/receipt"));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');

        // Renouvellement anticipé : la période suivante s'ajoute à la fin de la période en cours
        $this->waveStatus = 'processing';
        $second = $this->storeApi($this->store, 'POST', 'billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly', 'method' => 'wave'])->json('data.number');
        $this->waveStatus = 'succeeded';
        $this->storeApi($this->store, 'POST', "billing/invoices/{$second}/check")->assertJsonPath('data.status', 'paid');
        $this->assertTrue($sub->fresh()->current_period_end->between(now()->addMonths(2)->subMinute(), now()->addMonths(2)->addMinute()));

        $this->storeApi($this->store, 'POST', 'billing/checkout', ['plan' => 'free', 'cycle' => 'monthly', 'method' => 'wave'])->assertStatus(422);
    }

    public function test_trial_end_renewal_and_grace_period(): void
    {
        Notification::fake();
        $trial = $this->store->currentSubscription()->first();

        // Rappel avant la fin de l'essai, une seule fois
        $trial->update(['trial_ends_at' => now()->addDays(2)]);
        $this->artisan('billing:run')->assertSuccessful();
        $this->artisan('billing:run')->assertSuccessful();
        Notification::assertSentToTimes($this->store->owner, BillingNotice::class, 1);

        // Fin de l'essai : offre gratuite, boutique ouverte
        $trial->update(['trial_ends_at' => now()->subMinute()]);
        $this->artisan('billing:run');
        $this->assertSame(SubscriptionStatus::Expired, $trial->fresh()->status);
        $store = $this->store->fresh();
        $this->assertSame([StoreStatus::Active, 'free'], [$store->status, app(PlanLimits::class)->plan($store)->code]);

        // Abonnement payant arrivé à échéance : en retard, puis offre gratuite après le délai de grâce
        $paid = $store->subscriptions()->create(['plan_id' => Plan::where('code', 'starter')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subHour()]);
        $this->artisan('billing:run');
        $this->assertSame([SubscriptionStatus::PastDue, StoreStatus::PastDue], [$paid->fresh()->status, $store->fresh()->status]);
        $this->assertSame('starter', app(PlanLimits::class)->plan($store->fresh())->code);

        $paid->update(['current_period_end' => now()->subDays(8)]);
        $this->artisan('billing:run');
        $this->assertSame([SubscriptionStatus::Expired, StoreStatus::Active], [$paid->fresh()->status, $store->fresh()->status]);

        // Fin demandée par le marchand : pas de retard, retour direct à l'offre gratuite
        $other = $store->subscriptions()->create(['plan_id' => Plan::where('code', 'pro')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => now()->subMonth(), 'current_period_end' => now()->addDays(10)]);
        $this->storeApi($store, 'POST', 'billing/cancel')->assertOk()->assertJsonPath('data.subscription.cancel_at_period_end', true);
        $other->update(['current_period_end' => now()->subMinute()]);
        $this->artisan('billing:run');
        $this->assertSame(SubscriptionStatus::Expired, $other->fresh()->status);
    }

    public function test_free_plan_limits(): void
    {
        Subscription::query()->delete();
        Plan::where('code', 'free')->update(['limits' => json_encode(['orders_per_month' => 2, 'members' => 1, 'carrier_connections' => 0, 'payment_accounts' => 0])]);
        app(Places::class)->provisionDefaultZone($this->store);
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe', 'variants' => [['price' => 10000, 'on_hand' => 20]]]);

        $order = fn (string $phone) => ['source' => 'whatsapp', 'customer_phone' => $phone, 'delivery_method' => 'pickup', 'items' => [['variant_id' => $robe->variants[0]->id, 'quantity' => 1]]];
        $this->storeApi($this->store, 'POST', 'orders', $order('0102030405'))->assertCreated();
        $this->storeApi($this->store, 'POST', 'orders', $order('0102030406'))->assertCreated();
        $this->storeApi($this->store, 'POST', 'orders', $order('0102030407'))->assertStatus(422)
            ->assertJsonPath('message', 'Votre offre Gratuit est limitée à 2 commandes par mois. Passez à une offre supérieure pour continuer à recevoir des commandes.');

        // Sur la boutique en ligne, le client reçoit un message neutre
        $this->store->forceFill(['storefront_enabled' => true])->save();
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $cart = $this->withCredentials()->withHeaders($headers)->postJson($this->storeUrl($this->store, '/panier/articles'), ['variant_id' => $robe->variants[0]->id, 'quantity' => 1])
            ->getCookie(CartService::COOKIE)->getValue();
        $this->withCredentials()->withHeaders($headers)->withCookie(CartService::COOKIE, $cart)->postJson($this->storeUrl($this->store, '/commande'), [
            'name' => 'Fatou', 'phone' => '0506070809', 'delivery_method' => 'delivery', 'place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
        ])->assertStatus(422)->assertJsonPath('message', 'La boutique ne peut pas prendre de nouvelle commande en ligne pour le moment. Contactez-la directement (WhatsApp ou téléphone).');

        $this->storeApi($this->store, 'POST', 'team/invitations', ['phone' => '0506070809', 'role' => 'cashier'])->assertStatus(422);
        $this->storeApi($this->store, 'POST', 'carriers/manual', ['name' => 'Moto'])->assertStatus(422);
        $this->storeApi($this->store, 'POST', 'payments/accounts', ['provider' => 'wave', 'credentials' => ['api_key' => 'x', 'webhook_secret' => 'y']])->assertStatus(422);
        $this->storeApi($this->store, 'PUT', 'whatsapp', ['phone_number_id' => '1', 'access_token' => 't', 'app_secret' => 's'])
            ->assertStatus(422)->assertJsonPath('message', 'WhatsApp automatique : fonction comprise à partir de l\'offre Pro. Changez d\'offre dans Réglages › Abonnement.');

        $usage = $this->storeApi($this->store, 'GET', 'billing')->json('data.usage');
        $this->assertSame(['used' => 2, 'limit' => 2], array_intersect_key($usage['orders_per_month'], array_flip(['used', 'limit'])));
        $this->assertSame(1, Product::count());
    }

    public function test_changing_plan_turns_unused_paid_days_into_extra_days(): void
    {
        // Offre Starter payée 5 000 F pour 30 jours, dont 16 restants : environ 2 666 F non utilisés
        Subscription::query()->delete();
        $starter = $this->store->subscriptions()->create(['plan_id' => Plan::where('code', 'starter')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => now()->subDays(14), 'current_period_end' => now()->addDays(16)]);
        SubscriptionInvoice::create(['store_id' => $this->store->id, 'subscription_id' => $starter->id, 'plan_id' => $starter->plan_id, 'number' => 'JM-2026-900001',
            'billing_cycle' => 'monthly', 'amount' => 5000, 'status' => 'paid', 'idempotency_key' => (string) Str::uuid(),
            'period_start' => now()->subDays(14), 'period_end' => now()->addDays(16), 'paid_at' => now()->subDays(14)]);

        $credit = $this->storeApi($this->store, 'GET', 'billing')->json('data.credit');
        $this->assertEqualsWithDelta(2666, $credit, 2);

        // Passage au Pro (15 000 F par mois, soit 500 F par jour) : 5 jours en plus
        $number = $this->storeApi($this->store, 'POST', 'billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly', 'method' => 'wave'])->assertCreated()->json('data.number');
        $this->waveStatus = 'succeeded';
        $this->storeApi($this->store, 'POST', "billing/invoices/{$number}/check")->assertJsonPath('data.status', 'paid')->assertJsonPath('data.credit_days', 5);

        $pro = $this->store->currentSubscription()->first();
        $this->assertSame('pro', $pro->plan->code);
        $this->assertTrue($pro->current_period_end->between(now()->addMonth()->addDays(5)->subMinute(), now()->addMonth()->addDays(5)->addMinute()));
        $this->assertSame(SubscriptionStatus::Cancelled, $starter->fresh()->status);

        // Plus rien à reporter sur l'offre remplacée ; un essai n'a jamais de crédit
        $this->assertSame(0, app(Billing::class)->unusedCredit($starter->fresh()));
    }

    public function test_the_team_is_warned_before_the_order_limit(): void
    {
        Notification::fake();
        Subscription::query()->delete();
        Plan::where('code', 'free')->update(['limits' => json_encode(['orders_per_month' => 5])]);
        app(Places::class)->provisionDefaultZone($this->store);
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe', 'variants' => [['price' => 10000, 'on_hand' => 20]]]);
        $order = fn (int $i) => ['source' => 'whatsapp', 'customer_phone' => '010203040'.$i, 'delivery_method' => 'pickup', 'items' => [['variant_id' => $robe->variants[0]->id, 'quantity' => 1]]];

        // 4 commandes sur 5 (80 %) : un avertissement, une seule fois dans le mois
        foreach (range(1, 3) as $i) {
            $this->storeApi($this->store, 'POST', 'orders', $order($i))->assertCreated();
        }
        Notification::assertNothingSent();
        $this->storeApi($this->store, 'POST', 'orders', $order(4))->assertCreated();
        $this->storeApi($this->store, 'POST', 'orders', $order(5))->assertCreated();
        Notification::assertSentToTimes($this->store->owner, BillingNotice::class, 1);
        Notification::assertSentTo($this->store->owner, BillingNotice::class, fn ($n) => $n->toArray($this->store->owner)['title'] === 'Limite de commandes bientôt atteinte'
            && str_contains($n->toArray($this->store->owner)['body'], '4 commandes sur 5'));

        // Limite dépassée : refus et second avis
        $this->storeApi($this->store, 'POST', 'orders', $order(6))->assertStatus(422);
        Notification::assertSentToTimes($this->store->owner, BillingNotice::class, 2);
    }

    public function test_online_payment_is_included_from_the_starter_plan(): void
    {
        Subscription::query()->delete();
        $this->store->subscriptions()->create(['plan_id' => Plan::where('code', 'starter')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);

        $this->storeApi($this->store, 'POST', 'payments/accounts', ['provider' => 'wave', 'credentials' => ['api_key' => 'x', 'webhook_secret' => 'y']])->assertCreated();

        // Fonctions annoncées mais pas encore prêtes : signalées « bientôt »
        $features = $this->storeApi($this->store, 'GET', 'billing')->assertJsonPath('data.coming_soon', ['merchant_api'])->json('data.features');
        $this->assertTrue($features['online_payment']['enabled']);
        $this->assertTrue($features['merchant_api']['soon']);
    }
}
