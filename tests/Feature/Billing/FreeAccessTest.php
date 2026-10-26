<?php

namespace Tests\Feature\Billing;

use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AdminAction;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Notifications\BillingNotice;
use App\Services\Billing\Billing;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Gratuité donnée depuis la console : pour tous (offre, date de fin, bandeau, délai à l'arrêt) et « gratuit à vie ».
 */
class FreeAccessTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private Store $store;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->create(['phone' => '+2250701020304']);
        $this->admin->forceFill(['is_super_admin' => true])->save();
        // Essai terminé : l'espace marchand est à l'offre gratuite
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode'], plan: null);
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        // Session de console ouverte (mot de passe et code déjà vérifiés : voir ConsoleTest)
        return $this->actingAs($this->admin)->withSession(['console_admin' => $this->admin->id, 'console_since' => now()->timestamp])
            ->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    private function billing(): TestResponse
    {
        $this->actingAs($this->store->owner);

        return $this->storeApi($this->store, 'GET', 'billing')->assertOk();
    }

    public function test_free_for_everyone_then_a_grace_period_when_it_stops(): void
    {
        $this->billing()->assertJsonPath('data.plan.code', 'free')->assertJsonPath('data.free', null);

        $this->console('PUT', 'free', ['enabled' => true, 'plan' => 'free', 'grace_days' => 14])->assertStatus(422);
        $this->console('PUT', 'free', ['enabled' => true, 'plan' => 'pro', 'until' => null, 'banner' => 'Jibiat Marchand est gratuit en ce moment', 'grace_days' => 14])
            ->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.plan', 'pro');

        $this->billing()->assertJsonPath('data.plan.code', 'pro')->assertJsonPath('data.free.forever', false)
            ->assertJsonPath('data.usage.ai_actions_per_month.limit', Plan::where('code', 'pro')->first()->limit('ai_actions_per_month'));
        $this->storeApi($this->store, 'GET', 'auth/me')->assertJsonPath('data.store.free.banner', 'Jibiat Marchand est gratuit en ce moment');

        // Un essai qui se termine pendant la gratuité ne fait rien perdre
        $other = $this->createStore(['slug' => 'koffi']);
        $other->currentSubscription->update(['trial_ends_at' => now()->subDay(), 'current_period_end' => now()->subDay()]);
        app(Billing::class)->run();
        $this->assertSame(SubscriptionStatus::Trialing, $other->currentSubscription()->first()->status);

        // Arrêt : chacun garde l'offre Pro pendant le délai, puis choisit la sienne
        $this->console('PUT', 'free', ['enabled' => false, 'plan' => 'pro', 'grace_days' => 14])->assertOk()->assertJsonPath('data.active', false);
        $this->assertSame(2, AdminAction::where('action', 'free_updated')->latest('id')->first()->data['grace_given']);
        foreach ([$this->store, $other] as $store) {
            $sub = $store->currentSubscription()->first();
            $this->assertSame([SubscriptionStatus::Trialing, 'pro'], [$sub->status, $sub->plan->code]);
            $this->assertTrue($sub->trial_ends_at->isSameDay(now()->addDays(14)));
        }
        $this->assertSame(StoreStatus::Trial, $this->store->fresh()->status);
        Notification::assertSentTo($this->store->owner, BillingNotice::class);
        $this->billing()->assertJsonPath('data.free', null)->assertJsonPath('data.subscription.status', 'trialing');

        // Après le délai : fonctionnement normal (offre gratuite)
        $this->travel(15)->days();
        app(Billing::class)->run();
        $this->billing()->assertJsonPath('data.plan.code', 'free');
    }

    public function test_free_for_everyone_until_a_date(): void
    {
        $this->console('PUT', 'free', ['enabled' => true, 'plan' => 'starter', 'until' => now()->addDays(3)->toDateString(), 'grace_days' => 7])->assertOk();
        $this->billing()->assertJsonPath('data.plan.code', 'starter')->assertJsonPath('data.free.until', now()->addDays(3)->toDateString());

        $this->travel(4)->days();
        $this->assertSame(1, app(Billing::class)->run()['free_ended']);
        $this->console('GET', 'free')->assertJsonPath('data.enabled', false)->assertJsonPath('data.active', false);
        $this->billing()->assertJsonPath('data.free', null)->assertJsonPath('data.plan.code', 'starter')->assertJsonPath('data.subscription.status', 'trialing');
    }

    public function test_free_forever_for_one_store_and_a_paid_higher_plan_is_kept(): void
    {
        $this->console('POST', "stores/{$this->store->id}/free", ['plan' => 'business'])->assertOk()
            ->assertJsonPath('data.free_forever', 'business')->assertJsonPath('data.free.forever', true);
        $this->billing()->assertJsonPath('data.plan.code', 'business')->assertJsonPath('data.free.forever', true)->assertJsonPath('data.free.banner', null);

        // L'arrêt de la gratuité pour tous ne le touche pas
        $this->console('PUT', 'free', ['enabled' => true, 'plan' => 'pro', 'grace_days' => 14])->assertOk();
        $this->console('PUT', 'free', ['enabled' => false, 'plan' => 'pro', 'grace_days' => 14])->assertOk();
        $this->assertNull($this->store->currentSubscription()->first());
        $this->billing()->assertJsonPath('data.plan.code', 'business');

        // Retrait : retour à son offre
        $this->console('POST', "stores/{$this->store->id}/free", ['plan' => null])->assertOk()->assertJsonPath('data.free_forever', null);
        $this->billing()->assertJsonPath('data.plan.code', 'free');
        $this->assertSame(['free_forever', 'free_forever_removed'], AdminAction::where('store_id', $this->store->id)->orderBy('id')->pluck('action')->all());

        // Une boutique qui paie déjà plus que l'offre donnée garde la sienne
        $paid = $this->createStore(['slug' => 'mariam'], plan: 'business');
        $paid->currentSubscription->update(['status' => SubscriptionStatus::Active]);
        $this->console('PUT', 'free', ['enabled' => true, 'plan' => 'starter', 'grace_days' => 14])->assertOk();
        $this->actingAs($paid->owner);
        $this->storeApi($paid, 'GET', 'billing')->assertJsonPath('data.plan.code', 'business');
    }
}
