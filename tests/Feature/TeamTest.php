<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\StoreInvitation;
use App\Models\User;
use App\Services\Accounts\StoreRoles;
use Tests\TestCase;

class TeamTest extends TestCase
{
    public function test_the_owner_invites_a_member_and_gets_a_whatsapp_link(): void
    {
        $store = $this->createStore(['name' => 'Awa Mode']);
        $this->actingAs($store->owner);

        $response = $this->storeApi($store, 'POST', 'team/invitations', ['phone' => '05 06 07 08 09', 'role' => 'confirmation_agent', 'name' => 'Fatou'])
            ->assertCreated()
            ->assertJsonPath('data.role_label', 'Agent de confirmation')
            ->assertJsonPath('data.phone', '05 06 07 08 09');

        $this->assertStringStartsWith($this->storeUrl($store, '/invitation/'), $response->json('link'));
        $this->assertStringStartsWith('https://wa.me/2250506070809?text=', $response->json('whatsapp_url'));
        $this->assertStringContainsString('Awa Mode', $response->json('message'));

        $this->storeApi($store, 'GET', 'team/invitations')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_invitations_are_validated(): void
    {
        $store = $this->createStore();
        $member = $this->addMember($store);
        $this->actingAs($store->owner);

        $this->storeApi($store, 'POST', 'team/invitations', ['phone' => $member->phone, 'role' => 'cashier'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->storeApi($store, 'POST', 'team/invitations', ['phone' => '0506070809', 'role' => 'owner'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_a_new_invitation_replaces_the_previous_one_for_the_same_phone(): void
    {
        $store = $this->createStore();
        $this->actingAs($store->owner);

        $first = $this->storeApi($store, 'POST', 'team/invitations', ['phone' => '0506070809', 'role' => 'cashier'])->json('link');
        $this->storeApi($store, 'POST', 'team/invitations', ['phone' => '0506070809', 'role' => 'manager']);

        $this->logout();
        $this->storeApi($store, 'GET', 'invitations/'.basename($first))->assertNotFound();
    }

    public function test_only_team_managers_handle_the_team(): void
    {
        $store = $this->createStore();
        $agent = $this->addMember($store, null, Role::ConfirmationAgent);

        $this->actingAs($agent);
        $this->storeApi($store, 'GET', 'team/members')->assertForbidden();
        $this->storeApi($store, 'POST', 'team/invitations', ['phone' => '0506070809', 'role' => 'cashier'])->assertForbidden();
    }

    public function test_someone_with_an_account_joins_with_their_password(): void
    {
        $store = $this->createStore(['name' => 'Awa Mode']);
        $other = $this->createStore();
        $person = $other->owner;

        $this->actingAs($store->owner);
        $link = $this->storeApi($store, 'POST', 'team/invitations', ['phone' => $person->phone, 'role' => 'stock_keeper'])->json('link');
        $token = basename($link);
        $this->logout();

        $this->storeApi($store, 'GET', "invitations/{$token}")->assertOk()
            ->assertJsonPath('data.store', 'Awa Mode')
            ->assertJsonPath('data.role', 'Préparateur / magasinier')
            ->assertJsonPath('data.has_account', true);

        // L'invitation ne vaut que sur sa boutique
        $this->storeApi($other, 'GET', "invitations/{$token}")->assertNotFound();

        $this->storeApi($store, 'POST', "invitations/{$token}/accept", ['password' => 'mauvais'])->assertStatus(422);
        $this->storeApi($store, 'POST', "invitations/{$token}/accept", ['password' => 'password'])->assertOk()->assertJson(['redirect' => '/gestion']);

        $this->assertAuthenticatedAs($person, 'web');
        $this->assertSame(Role::StockKeeper, app(StoreRoles::class)->roleOf($person, $store));
        // Son rôle dans son autre boutique n'a pas changé
        $this->assertSame(Role::Owner, app(StoreRoles::class)->roleOf($person, $other));

        $this->storeApi($store, 'GET', "invitations/{$token}")->assertNotFound();
    }

    public function test_a_new_person_creates_an_account_after_verifying_the_phone(): void
    {
        $store = $this->createStore();
        $this->actingAs($store->owner);
        $token = basename($this->storeApi($store, 'POST', 'team/invitations', ['phone' => '0506070809', 'role' => 'cashier'])->json('link'));
        $this->logout();

        $this->storeApi($store, 'GET', "invitations/{$token}")->assertJsonPath('data.has_account', false);

        $start = $this->storeApi($store, 'POST', "invitations/{$token}/accept", ['name' => 'Fatou Diallo', 'password' => 'motdepasse'])
            ->assertStatus(202)->json();
        $this->assertSame('+2250506070809', $this->sentSms()[0]['to']);

        $this->storeApi($store, 'POST', "verifications/{$start['verification']['token']}/confirm", ['code' => $start['demo_code']])
            ->assertOk()->assertJson(['redirect' => '/gestion']);

        $user = User::where('phone', '+2250506070809')->firstOrFail();
        $this->assertSame('Fatou Diallo', $user->name);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(Role::Cashier, app(StoreRoles::class)->roleOf($user, $store));
        $this->assertNotNull(StoreInvitation::forStore($store->id)->first()->accepted_at);
    }

    public function test_members_roles_can_be_changed_suspended_and_removed(): void
    {
        $store = $this->createStore();
        $member = $this->addMember($store, null, Role::Cashier);
        $membershipId = $store->members()->where('user_id', $member->id)->value('id');
        $this->actingAs($store->owner);

        $members = $this->storeApi($store, 'GET', 'team/members')->assertOk()->json('data');
        $this->assertCount(2, $members);
        $this->assertTrue(collect($members)->firstWhere('user_id', $store->owner_id)['is_owner']);

        $this->storeApi($store, 'PATCH', "team/members/{$membershipId}", ['role' => 'manager'])->assertOk()->assertJsonPath('data.role', 'manager');
        $this->storeApi($store, 'PATCH', "team/members/{$membershipId}", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->storeApi($store, 'PATCH', "team/members/{$membershipId}", ['role' => 'owner'])->assertStatus(422);

        $this->storeApi($store, 'DELETE', "team/members/{$membershipId}")->assertOk();
        $this->assertDatabaseMissing('store_users', ['id' => $membershipId]);
        $this->assertNull(app(StoreRoles::class)->roleOf($member, $store));
    }

    public function test_the_owner_and_oneself_cannot_be_modified(): void
    {
        $store = $this->createStore();
        $manager = $this->addMember($store, null, Role::Manager);
        $ownerMembership = $store->members()->where('user_id', $store->owner_id)->value('id');
        $managerMembership = $store->members()->where('user_id', $manager->id)->value('id');

        $this->actingAs($manager);
        $this->storeApi($store, 'PATCH', "team/members/{$ownerMembership}", ['status' => 'suspended'])->assertStatus(422);
        $this->storeApi($store, 'DELETE', "team/members/{$ownerMembership}")->assertStatus(422);
        $this->storeApi($store, 'PATCH', "team/members/{$managerMembership}", ['role' => 'cashier'])->assertStatus(422);
    }

    public function test_members_of_another_store_are_out_of_reach(): void
    {
        $store = $this->createStore();
        $other = $this->createStore();
        $outsider = $this->addMember($other, null, Role::Cashier);
        $outsiderMembership = $other->members()->where('user_id', $outsider->id)->value('id');

        $this->actingAs($store->owner);
        $this->storeApi($store, 'PATCH', "team/members/{$outsiderMembership}", ['role' => 'manager'])->assertNotFound();
        $this->storeApi($store, 'DELETE', "team/members/{$outsiderMembership}")->assertNotFound();
    }
}
