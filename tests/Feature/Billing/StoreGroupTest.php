<?php

namespace Tests\Feature\Billing;

use App\Enums\Role;
use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Store;
use App\Services\Billing\PlanLimits;
use Tests\TestCase;

class StoreGroupTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->actingAs($this->store->owner);
    }

    public function test_an_owner_creates_another_store_from_the_management(): void
    {
        $this->storeApi($this->store, 'GET', 'my-stores')->assertOk()
            ->assertJsonCount(1, 'data.stores')->assertJsonPath('data.stores.0.is_current', true)->assertJsonPath('data.group', null);

        $this->storeApi($this->store, 'POST', 'my-stores', ['name' => 'Awa Kids', 'slug' => 'awa'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $redirect = $this->storeApi($this->store, 'POST', 'my-stores', ['name' => 'Awa Kids', 'slug' => 'Awa-Kids'])->assertCreated()->json('redirect');
        $this->assertStringStartsWith('http://awa-kids.localhost/auth/relais?jeton=', $redirect);

        // Offre sans plusieurs boutiques : la nouvelle boutique a son propre essai
        $kids = Store::where('slug', 'awa-kids')->sole();
        $this->assertSame([$this->store->owner_id, null], [$kids->owner_id, $kids->billing_store_id]);
        $this->assertSame(SubscriptionStatus::Trialing, $kids->currentSubscription->status);
        $this->storeApi($kids, 'GET', 'auth/me')->assertJsonPath('data.role.value', 'owner');
        $this->storeApi($this->store, 'GET', 'my-stores')->assertJsonCount(2, 'data.stores');

        // Réservé au propriétaire
        $this->actingAs($this->addMember($this->store, role: Role::Manager));
        $this->storeApi($this->store, 'GET', 'my-stores')->assertForbidden();
        $this->storeApi($this->store, 'POST', 'my-stores', ['name' => 'Autre', 'slug' => 'autre'])->assertForbidden();
    }

    public function test_the_business_plan_covers_several_stores(): void
    {
        $this->store->currentSubscription()->first()->update(['status' => SubscriptionStatus::Expired]);
        $business = $this->store->subscriptions()->create(['plan_id' => Plan::where('code', 'business')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $limits = app(PlanLimits::class);

        // Nouvelle boutique : couverte d'office, sans essai
        $this->storeApi($this->store, 'POST', 'my-stores', ['name' => 'Awa Kids', 'slug' => 'awa-kids'])->assertCreated();
        $kids = Store::where('slug', 'awa-kids')->sole();
        $this->assertSame([$this->store->id, StoreStatus::Active, null], [$kids->billing_store_id, $kids->status, $kids->currentSubscription]);
        $this->assertSame('business', $limits->plan($kids)->code);

        // Boutique existante du même propriétaire : son essai s'arrête, l'abonnement commun la couvre
        $shoes = $this->createStore(['slug' => 'awa-shoes', 'name' => 'Awa Shoes', 'owner_id' => $this->store->owner_id]);
        $this->storeApi($this->store, 'POST', "my-stores/{$shoes->id}/attach")->assertOk()
            ->assertJsonPath('data.group.used', 3)->assertJsonPath('data.group.limit', 3)->assertJsonPath('data.stores.2.covered', true);
        $this->assertSame(SubscriptionStatus::Expired, $shoes->subscriptions()->sole()->status);
        $this->assertSame('business', $limits->plan($shoes->fresh())->code);

        // Trois boutiques au plus ; jamais la boutique d'un autre propriétaire
        $fourth = $this->createStore(['slug' => 'awa-sacs', 'owner_id' => $this->store->owner_id]);
        $this->storeApi($this->store, 'POST', "my-stores/{$fourth->id}/attach")->assertStatus(422)->assertJsonPath('message', 'Votre offre Business couvre 3 boutiques au plus.');
        $this->storeApi($this->store, 'DELETE', "my-stores/{$shoes->id}/attach")->assertOk();
        $other = $this->createStore(['slug' => 'voisin']);
        $this->storeApi($this->store, 'POST', "my-stores/{$other->id}/attach")->assertStatus(422);
        $this->assertNull($other->fresh()->billing_store_id);

        // Retirée : elle reprend sa propre offre (gratuite, son essai est terminé)
        $this->assertSame('free', $limits->plan($shoes->fresh())->code);

        // La boutique couverte ne paie rien de son côté
        $this->storeApi($kids, 'GET', 'billing')->assertJsonPath('data.plan.code', 'business')->assertJsonPath('data.covered_by.name', 'Awa Mode');
        $this->storeApi($kids, 'POST', 'billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly', 'method' => 'wave'])->assertStatus(422);
        $this->storeApi($kids, 'GET', 'auth/me')->assertJsonPath('data.store.subscription.covered_by', 'Awa Mode');

        // Fin de l'abonnement Business : chaque boutique revient à sa propre offre
        $business->update(['status' => SubscriptionStatus::Expired]);
        $this->assertSame('free', $limits->plan($kids->fresh())->code);
    }
}
