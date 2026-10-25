<?php

namespace Tests\Feature\Partners;

use App\Models\AdminAction;
use App\Models\CarrierConnection;
use App\Models\Plan;
use App\Models\ReferralCommission;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Billing\Billing;
use App\Services\Partners\PartnerReferrals;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Jibiat\Socle\Contract\Contract;
use Tests\TestCase;

/**
 * Parrainage par les entreprises de livraison : part de l'abonnement payé (20 % pendant 12 mois à partir du premier
 * paiement), compte envoyé à l'entreprise (contrat merchant-app), entreprise attitrée, versements dans la console.
 */
class PartnerReferralTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private const API = 'https://rapide.jibiat.com/api/public/v1';

    private const KEY = 'lv_abcd1234_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private Store $store;

    /** @var list<array<string, mixed>> */
    private array $reports = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_starts_with($url, config('platform.delivery_app_directory_url'))) {
                return Http::response(Contract::example('directory'));
            }
            if ($request->method() === 'PUT' && $url === self::API.'/merchant-app') {
                $this->reports[] = $request->data();
                $this->assertSame('Bearer '.self::KEY, $request->header('Authorization')[0]);

                return Http::response(['data' => [...$request->data(), 'reported_at' => now()->toIso8601String()]]);
            }

            return match (true) {
                $url === self::API.'/me' => Http::response(Contract::example('me')),
                $url === self::API.'/zones' => Http::response(Contract::example('zones')),
                str_starts_with($url, self::API.'/webhooks') => Http::response(['secret' => 'whsec_secret'] + Contract::example('webhook-created'), 201),
                str_starts_with($url, self::API.'/quotes') => Http::response(Contract::example('quote')),
                default => Http::response(['message' => 'Introuvable'], 404),
            };
        });
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->store->forceFill(['referred_by' => 'rapide'])->save();
    }

    private function paidInvoice(int $amount = 12500, string $kind = 'subscription'): SubscriptionInvoice
    {
        $invoice = SubscriptionInvoice::create([
            'store_id' => $this->store->id, 'kind' => $kind, 'plan_id' => Plan::where('code', 'pro')->value('id'), 'number' => 'F-'.uniqid(),
            'billing_cycle' => 'monthly', 'amount' => $amount, 'status' => 'pending', 'provider' => 'wave_link', 'method' => 'wave', 'idempotency_key' => (string) Str::uuid(),
            'ai_actions' => $kind === 'ai_pack' ? 100 : null,
        ]);

        return app(Billing::class)->decide($invoice, true);
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    public function test_each_paid_subscription_earns_the_partner_its_share_for_twelve_months(): void
    {
        $this->assertSame('paid', $this->paidInvoice()->status);
        $this->assertSame(2500, ReferralCommission::sole()->amount);

        // Recharge de l'assistant : pas un abonnement
        $this->paidInvoice(3000, 'ai_pack');
        $this->assertSame(1, ReferralCommission::count());

        // Après 12 mois à partir du premier paiement : plus rien
        $this->travel(11)->months();
        $this->paidInvoice();
        $this->travel(2)->months();
        $this->paidInvoice();
        $this->assertSame([2500, 2500], ReferralCommission::orderBy('id')->pluck('amount')->all());

        // Boutique venue sans entreprise : rien
        $this->store->forceFill(['referred_by' => null])->save();
        $this->travelBack();
        $this->paidInvoice();
        $this->assertSame(2, ReferralCommission::count());
    }

    public function test_the_partner_is_the_preferred_carrier_and_receives_the_account(): void
    {
        $this->paidInvoice();
        $this->assertSame([], $this->reports); // pas encore connectée à l'entreprise

        // L'annuaire montre l'entreprise qui a invité la boutique en premier
        $this->actingAs($this->store->owner);
        $this->storeApi($this->store, 'GET', 'carriers/directory')->assertOk()
            ->assertJsonPath('data.0.slug', 'rapide')->assertJsonPath('data.0.partner', true)->assertJsonPath('data.1.partner', false);

        // Connexion : l'entreprise reçoit tout de suite le compte de la boutique (contrat merchant-app)
        $this->storeApi($this->store, 'POST', 'carriers/delivery-app', ['address' => 'rapide.jibiat.com', 'api_key' => self::KEY])
            ->assertCreated()->assertJsonPath('data.partner', true);
        $this->assertCount(1, $this->reports);
        $report = $this->reports[0];
        $this->assertSame([], Contract::validate('merchant-app', ['data' => [...$report, 'reported_at' => now()->toIso8601String()]]));
        $this->assertSame(['rate' => 0.2, 'months' => 12, 'currency' => 'XOF', 'earned_total' => 2500, 'earned_month' => 2500, 'paid_total' => 0],
            array_diff_key($report['commission'], ['ends_at' => 1]));
        $this->assertSame(now()->addMonths(12)->toDateString(), $report['commission']['ends_at']);
        $this->assertSame('Awa Mode', $report['store_name']);

        // Formulaire de commande : « votre entreprise » proposée en premier
        CarrierConnection::create(['store_id' => $this->store->id, 'driver' => 'manual', 'name' => 'Aaa Coursiers', 'status' => 'active']);
        $carriers = $this->storeApi($this->store, 'POST', 'orders/delivery-options', [])->assertOk()->json('data.carriers');
        $this->assertTrue($carriers[0]['partner']);
        $this->assertFalse($carriers[1]['partner']);

        // Rapport quotidien
        $this->artisan('referrals:report')->assertSuccessful();
        $this->assertCount(2, $this->reports);
    }

    public function test_the_console_sets_the_share_and_pays_the_partner(): void
    {
        $this->paidInvoice();
        $admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $start = $this->console('POST', 'auth/login', ['phone' => '07 01 02 03 04', 'password' => 'motdepasse'])->assertStatus(202);
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])->assertOk();

        $this->console('GET', 'referrals')->assertOk()
            ->assertJsonPath('data.settings.rate', 0.2)
            ->assertJsonPath('data.partners.0.name', 'Rapide Express')
            ->assertJsonPath('data.partners.0.due', 2500)
            ->assertJsonPath('data.partners.0.stores.0.name', 'Awa Mode');

        $this->console('POST', 'referrals/rapide/pay', ['method' => 'wave', 'reference' => 'TX-1'])->assertOk()
            ->assertJsonPath('data.partners.0.due', 0)->assertJsonPath('data.partners.0.paid_total', 2500)
            ->assertJsonPath('data.partners.0.payouts.0.amount', 2500);
        $this->console('POST', 'referrals/rapide/pay', [])->assertStatus(422);
        $this->assertSame(1, AdminAction::where('action', 'referral_paid')->count());

        // Nouvelle part : comptée pour les paiements suivants
        $this->console('PUT', 'referrals/settings', ['rate' => 0.25, 'months' => 6])->assertOk()->assertJsonPath('data.settings.months', 6);
        $this->paidInvoice(10000);
        $this->assertSame(2500, ReferralCommission::latest('id')->first()->amount);
        $this->assertSame(now()->addMonths(6)->toDateString(), app(PartnerReferrals::class)->endsAt($this->store)->toDateString());
        $this->console('PUT', 'referrals/settings', ['rate' => 0.9, 'months' => 6])->assertStatus(422);
    }
}
