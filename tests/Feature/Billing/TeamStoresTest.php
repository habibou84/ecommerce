<?php

namespace Tests\Feature\Billing;

use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Courier;
use App\Models\Plan;
use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\User;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\StoreRoles;
use Tests\TestCase;

class TeamStoresTest extends TestCase
{
    private Store $mode;

    private Store $kids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mode = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->mode->currentSubscription()->first()->update(['status' => SubscriptionStatus::Expired]);
        $this->mode->subscriptions()->create(['plan_id' => Plan::where('code', 'business')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $this->actingAs($this->mode->owner);
        $this->storeApi($this->mode, 'POST', 'my-stores', ['name' => 'Awa Kids', 'path' => 'kids'])->assertCreated();
        $this->kids = Store::where('path', 'kids')->sole();
    }

    public function test_one_invitation_for_several_stores_and_a_role_in_each(): void
    {
        $this->storeApi($this->mode, 'GET', 'team/stores')->assertJsonPath('data.*.name', ['Awa Mode', 'Awa Kids']);

        $sent = $this->storeApi($this->mode, 'POST', 'team/invitations', [
            'phone' => '0102030405', 'role' => 'manager', 'name' => 'Fatou', 'stores' => [$this->kids->id => 'confirmation_agent'],
        ])->assertCreated();
        $this->assertStringContainsString("l'équipe de Awa Mode et Awa Kids", $sent->json('message'));
        $this->assertSame([['store_id' => $this->kids->id, 'role' => 'confirmation_agent']], StoreInvitation::withoutGlobalScopes()->sole()->extra_stores);

        // Un seul lien : elle rejoint les deux boutiques avec son mot de passe
        $fatou = User::factory()->create(['name' => 'Fatou', 'phone' => '+2250102030405', 'password' => 'password']);
        $token = basename(parse_url($sent->json('link'), PHP_URL_PATH));
        $this->storeApi($this->mode, 'GET', "invitations/{$token}")->assertJsonPath('data.other_stores', [['name' => 'Awa Kids', 'role' => 'Agent de confirmation']]);
        app(Invitations::class)->acceptWithPassword(StoreInvitation::withoutGlobalScopes()->sole(), 'password');
        $roles = app(StoreRoles::class);
        $this->assertSame([Role::Manager, Role::ConfirmationAgent], [$roles->roleOf($fatou, $this->mode), $roles->roleOf($fatou, $this->kids)]);

        // Équipe : ses boutiques et son rôle dans chacune ; modifiables sans nouvelle invitation
        $member = collect($this->storeApi($this->mode, 'GET', 'team/members')->json('data'))->firstWhere('name', 'Fatou');
        $this->assertSame([['Awa Mode', 'manager'], ['Awa Kids', 'confirmation_agent']], array_map(fn ($s) => [$s['name'], $s['role']], $member['stores']));

        $this->storeApi($this->mode, 'PUT', "team/members/{$member['id']}/stores", ['stores' => [$this->kids->id => null]])->assertOk()
            ->assertJsonPath('data.stores.*.name', ['Awa Mode']);
        $this->assertNull($fatou->activeMembershipIn($this->kids));
        $this->storeApi($this->mode, 'PUT', "team/members/{$member['id']}/stores", ['stores' => [$this->kids->id => 'cashier']])->assertOk();
        $this->assertSame(Role::Cashier, $roles->roleOf($fatou, $this->kids));

        // Le propriétaire ne se modifie pas ; le rôle de propriétaire ne se donne pas
        $owner = collect($this->storeApi($this->mode, 'GET', 'team/members')->json('data'))->firstWhere('is_owner', true);
        $this->storeApi($this->mode, 'PUT', "team/members/{$owner['id']}/stores", ['stores' => [$this->kids->id => null]])->assertStatus(422);
        $this->storeApi($this->mode, 'PUT', "team/members/{$member['id']}/stores", ['stores' => [$this->kids->id => 'owner']])->assertStatus(422);
    }

    public function test_only_stores_where_one_manages_the_team(): void
    {
        // Gérante d'Awa Mode seulement : elle n'invite pas pour Awa Kids
        $manager = $this->addMember($this->mode, User::factory()->create(), Role::Manager);
        $this->actingAs($manager);
        $this->storeApi($this->mode, 'GET', 'team/stores')->assertJsonPath('data.*.name', ['Awa Mode']);
        $this->storeApi($this->mode, 'POST', 'team/invitations', ['phone' => '0102030405', 'role' => 'cashier', 'stores' => [$this->kids->id => 'cashier']])
            ->assertStatus(422)->assertJsonPath('message', 'Vous ne gérez pas l\'équipe de cette boutique.');

        // Une boutique d'une autre adresse n'est jamais proposée
        $other = $this->createStore(['slug' => 'voisin', 'owner_id' => $this->mode->owner_id]);
        $this->actingAs($this->mode->owner);
        $this->storeApi($this->mode, 'POST', 'team/invitations', ['phone' => '0102030405', 'role' => 'cashier', 'stores' => [$other->id => 'cashier']])->assertStatus(422);
    }

    public function test_a_courier_added_for_several_stores(): void
    {
        // Déjà membre (compte actif) : livreur des deux boutiques tout de suite
        $koffi = $this->addMember($this->mode, User::factory()->create(['name' => 'Koffi', 'phone' => '+2250708091011']), Role::Cashier);
        $this->storeApi($this->mode, 'POST', 'couriers', ['name' => 'Koffi', 'phone' => '0708091011', 'store_ids' => [$this->kids->id]])->assertCreated()
            ->assertJsonPath('invitation_url', null);
        $this->assertSame([$koffi->id, 'active'], [Courier::forStore($this->kids->id)->sole()->user_id, Courier::forStore($this->kids->id)->sole()->status]);

        // Pas encore de compte : la boutique supplémentaire est rejointe avec l'invitation
        $link = $this->storeApi($this->mode, 'POST', 'couriers', ['name' => 'Yao', 'phone' => '0708091012', 'store_ids' => [$this->kids->id]])->assertCreated()->json('invitation_url');
        $yao = User::factory()->create(['name' => 'Yao', 'phone' => '+2250708091012', 'password' => 'password']);
        $this->app['auth']->forgetGuards();
        $this->storeApi($this->mode, 'POST', 'invitations/'.basename(parse_url($link, PHP_URL_PATH)).'/accept', ['password' => 'password'])->assertOk();
        $this->assertSame('active', Courier::forStore($this->kids->id)->where('user_id', $yao->id)->sole()->status);
    }
}
