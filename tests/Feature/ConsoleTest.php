<?php

namespace Tests\Feature;

use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AdminAction;
use App\Models\AiUsage;
use App\Models\Store;
use App\Models\SupportSession;
use App\Models\User;
use App\Notifications\SupportAccess;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ConsoleTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse', 'name' => 'Équipe Jibiat']);
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    private function signIn(): void
    {
        $start = $this->console('POST', 'auth/login', ['phone' => '07 01 02 03 04', 'password' => 'motdepasse'])->assertStatus(202);
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])
            ->assertOk()->assertJsonPath('data.name', 'Équipe Jibiat');
    }

    public function test_console_login_needs_password_sms_code_and_admin_rights(): void
    {
        $this->console('GET', 'stats')->assertUnauthorized();
        $this->console('POST', 'auth/login', ['phone' => '0701020304', 'password' => 'mauvais'])->assertStatus(422);
        // Un marchand ne se connecte pas à la console, même avec le bon mot de passe
        $this->store->owner->forceFill(['password' => 'secret123'])->save();
        $this->console('POST', 'auth/login', ['phone' => $this->store->owner->phone, 'password' => 'secret123'])->assertStatus(422);

        // Mot de passe juste mais code pas encore saisi : pas d'accès
        $start = $this->console('POST', 'auth/login', ['phone' => '0701020304', 'password' => 'motdepasse'])->assertStatus(202);
        $this->console('GET', 'stats')->assertUnauthorized();
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => '000000'])->assertStatus(422);
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])->assertOk();

        $this->console('GET', 'stats')->assertOk()->assertJsonPath('data.stores', 1)->assertJsonPath('data.trials_ending_7d', 0);
        // La console n'existe que sur admin.* ; une session de boutique n'y donne pas accès
        $this->storeApi($this->store, 'GET', 'console/stats')->assertNotFound();
        $this->assertSame(1, AdminAction::where('action', 'console_login')->count());
    }

    public function test_managing_stores_and_plans(): void
    {
        $this->signIn();
        $this->console('GET', 'stores?search=awa')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.plan', 'Pro')
            ->assertJsonPath('data.0.subscription_status', 'trialing');
        $this->console('GET', 'stores?plan=free')->assertJsonPath('meta.total', 0);

        $this->console('POST', "stores/{$this->store->id}/suspend", ['reason' => 'Produits interdits'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->get($this->storeUrl($this->store))->assertForbidden();
        $this->console('POST', "stores/{$this->store->id}/reactivate")->assertJsonPath('data.status', 'trial');

        $end = $this->store->currentSubscription()->first()->trial_ends_at;
        $this->console('POST', "stores/{$this->store->id}/trial", ['days' => 10])->assertOk();
        $this->assertTrue($this->store->currentSubscription()->first()->trial_ends_at->equalTo($end->addDays(10)));

        $this->console('POST', "stores/{$this->store->id}/offer", ['plan' => 'business', 'months' => 3])->assertOk()
            ->assertJsonPath('data.plan', 'Business')->assertJsonPath('data.invoices.0.status', 'offered')->assertJsonPath('data.invoices.0.amount', 0);
        $this->assertSame([SubscriptionStatus::Active, StoreStatus::Active], [$this->store->currentSubscription()->first()->status, $this->store->fresh()->status]);

        $plans = $this->console('GET', 'plans')->assertOk();
        $starter = collect($plans->json('data'))->firstWhere('code', 'starter');
        $this->console('PUT', "plans/{$starter['id']}", ['monthly_price' => 6003])->assertStatus(422);
        $this->console('PUT', "plans/{$starter['id']}", ['monthly_price' => 6000, 'limits' => ['members' => 4]])->assertOk()
            ->assertJsonPath('data.monthly_price', 6000)->assertJsonPath('data.limits.members', 4)->assertJsonPath('data.limits.products', null);

        $this->console('GET', 'invoices')->assertOk()->assertJsonPath('data.0.store.name', 'Awa Mode');
        $this->console('GET', "stores/{$this->store->id}")->assertJsonCount(4, 'data.actions');
    }

    public function test_support_session_into_a_store(): void
    {
        Notification::fake();
        $this->signIn();
        $this->console('POST', "stores/{$this->store->id}/support", ['reason' => 'x'])->assertStatus(422);
        $start = $this->console('POST', "stores/{$this->store->id}/support", ['reason' => 'Aide sur les zones de livraison', 'minutes' => 30])->assertCreated();
        Notification::assertSentTo($this->store->owner, SupportAccess::class);

        // Lien de connexion à la gestion de la boutique : rôle « support », sans l'abonnement ni l'équipe
        $this->get($start->json('redirect'))->assertRedirect('/gestion');
        $me = $this->storeApi($this->store, 'GET', 'auth/me')->assertOk()->assertJsonPath('data.role.value', 'support')->assertJsonPath('data.support.self', true);
        $this->assertContains('orders.manage', $me->json('data.permissions'));
        $this->assertNotContains('billing.manage', $me->json('data.permissions'));
        $this->storeApi($this->store, 'GET', 'orders')->assertOk();
        $this->storeApi($this->store, 'GET', 'billing')->assertForbidden();
        $this->storeApi($this->store, 'GET', 'team/members')->assertForbidden();

        // La boutique voit l'accès en cours et y met fin
        $this->logout();
        $this->actingAs($this->store->owner);
        $session = SupportSession::sole();
        $this->storeApi($this->store, 'GET', 'auth/me')->assertJsonPath('data.support.self', false)->assertJsonPath('data.support.admin', 'Équipe Jibiat');
        $this->storeApi($this->store, 'POST', "support-sessions/{$session->id}/end")->assertOk();
        $this->logout();
        $this->actingAs($this->admin);
        $this->storeApi($this->store, 'GET', 'orders')->assertForbidden();

        // Une session échue est fermée par la tâche planifiée
        $again = SupportSession::create(['store_id' => $this->store->id, 'admin_id' => $this->admin->id, 'reason' => 'Test', 'started_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);
        $this->artisan('support:expire')->expectsOutput('1 session(s) de support terminée(s)');
        $this->assertNotNull($again->fresh()->ended_at);
    }

    public function test_the_estimated_cost_of_the_ai_assistant(): void
    {
        $other = $this->createStore(['name' => 'Kids'], 'business');
        $now = now();
        foreach ([
            [$this->store, 'claude-opus-5-5', 1_000_000, 100_000, true],
            [$this->store, 'claude-haiku-5-5', 2_000_000, 200_000, true],
            [$this->store, 'claude-haiku-5-5', 5000, 0, false],
            [$other, 'claude-sonnet-5-5', 500_000, 50_000, true],
        ] as [$store, $model, $in, $out, $ok]) {
            AiUsage::create(['store_id' => $store->id, 'feature' => 'reply', 'model' => $model, 'input_tokens' => $in, 'output_tokens' => $out, 'succeeded' => $ok]);
        }
        // Mois précédent : pas compté
        AiUsage::create(['store_id' => $other->id, 'feature' => 'reply', 'model' => 'claude-opus-5-5', 'input_tokens' => 9_000_000, 'output_tokens' => 0])
            ->forceFill(['created_at' => $now->copy()->subMonthNoOverflow()->startOfMonth()->addDays(3)])->save();

        $this->signIn();
        // Opus : 4 $ + 2 $ ; Haiku : 0,20 $ + 0,10 $ + 0,0005 $ ; Sonnet : 1 $ + 0,50 $ ; 1 $ = 600 F
        $this->console('GET', 'ai-usage')->assertOk()
            ->assertJsonPath('data.total.actions', 3)->assertJsonPath('data.total.failures', 1)
            ->assertJsonPath('data.total.cost', 4680)
            ->assertJsonPath('data.stores.0.store.name', 'Awa Mode')->assertJsonPath('data.stores.0.cost', 3780)
            ->assertJsonPath('data.stores.0.quota', 300)
            ->assertJsonPath('data.stores.1.store.name', 'Kids')->assertJsonPath('data.stores.1.cost', 900);

        $this->console('GET', 'ai-usage?month='.$now->copy()->subMonthNoOverflow()->format('Y-m'))->assertJsonPath('data.total.cost', 21600);
        $this->console('GET', 'ai-usage?month=octobre')->assertStatus(422);
    }
}
