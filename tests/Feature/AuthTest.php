<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StoreStatus;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_a_member_logs_in_to_the_store_management(): void
    {
        $store = $this->createStore(['name' => 'Awa Mode']);
        $agent = $this->addMember($store, User::factory()->create(['phone' => '+2250102030405']), Role::ConfirmationAgent);

        $this->storeApi($store, 'POST', 'auth/login', ['phone' => '01 02 03 04 05', 'password' => 'password'])->assertOk()
            ->assertJsonPath('data.user.id', $agent->id)
            ->assertJsonPath('data.role.value', 'confirmation_agent')
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.store.name', 'Awa Mode');

        $this->assertAuthenticatedAs($agent, 'web');
        $permissions = $this->storeApi($store, 'GET', 'auth/me')->json('data.permissions');
        $this->assertSame(['customers.view', 'orders.confirm', 'orders.view', 'products.view'], $permissions);
        $this->assertNotNull($agent->fresh()->last_login_at);
    }

    public function test_wrong_credentials_and_outsiders_are_refused(): void
    {
        $store = $this->createStore();
        $outsider = User::factory()->create();

        $this->storeApi($store, 'POST', 'auth/login', ['phone' => $store->owner->phone, 'password' => 'mauvais'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->storeApi($store, 'POST', 'auth/login', ['phone' => $outsider->phone, 'password' => 'password'])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'Ce compte ne fait pas partie de l\'équipe de '.$store->name.'.');
        $this->assertGuest();
    }

    public function test_suspended_accounts_and_memberships_are_refused(): void
    {
        $store = $this->createStore();
        $suspended = $this->addMember($store, User::factory()->suspended()->create());
        $member = $this->addMember($store);
        $store->members()->where('user_id', $member->id)->update(['status' => UserStatus::Suspended->value]);

        $this->storeApi($store, 'POST', 'auth/login', ['phone' => $suspended->phone, 'password' => 'password'])->assertForbidden();
        $this->storeApi($store, 'POST', 'auth/login', ['phone' => $member->phone, 'password' => 'password'])->assertStatus(422);

        $this->actingAs($member);
        $this->storeApi($store, 'GET', 'auth/me')->assertForbidden();
    }

    public function test_a_session_never_opens_another_store(): void
    {
        $a = $this->createStore();
        $b = $this->createStore();

        $this->actingAs($a->owner);

        $this->storeApi($a, 'GET', 'store')->assertOk();
        $this->storeApi($b, 'GET', 'store')->assertForbidden()->assertJson(['message' => 'Vous n\'avez pas accès à cet espace marchand.']);
        $this->storeApi($b, 'GET', 'team/members')->assertForbidden();
    }

    public function test_roles_are_per_store(): void
    {
        $a = $this->createStore();
        $b = $this->createStore();
        $person = $this->addMember($a, null, Role::Manager);
        $this->addMember($b, $person, Role::Cashier);

        $this->actingAs($person);
        $this->storeApi($a, 'GET', 'auth/me')->assertJsonPath('data.role.value', 'manager');
        $this->storeApi($a, 'GET', 'team/members')->assertOk();
        $this->storeApi($b, 'GET', 'auth/me')->assertJsonPath('data.role.value', 'cashier');
        $this->storeApi($b, 'GET', 'team/members')->assertForbidden();
    }

    public function test_guests_cannot_reach_the_management_api(): void
    {
        $store = $this->createStore();

        $this->storeApi($store, 'GET', 'auth/me')->assertUnauthorized();
        $this->storeApi($store, 'GET', 'store')->assertUnauthorized();
    }

    public function test_logout_closes_the_session(): void
    {
        $store = $this->createStore();
        $this->actingAs($store->owner);

        $this->storeApi($store, 'POST', 'auth/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_switching_store_hands_over_a_single_use_login_link(): void
    {
        $a = $this->createStore();
        $b = $this->createStore(['status' => StoreStatus::Trial]);
        $closed = $this->createStore(['status' => StoreStatus::Suspended]);
        $person = $a->owner;
        $this->addMember($b, $person, Role::Cashier);
        $this->addMember($closed, $person, Role::Cashier);

        $this->actingAs($person);
        $me = $this->storeApi($a, 'GET', 'auth/me')->json('data');
        $this->assertSame([$b->id], array_column($me['other_stores'], 'id'));

        $link = $this->storeApi($a, 'POST', "auth/switch/{$b->id}")->assertOk()->json('redirect');
        $this->assertStringStartsWith($this->storeUrl($b, '/auth/relais?jeton='), $link);
        $this->storeApi($a, 'POST', "auth/switch/{$closed->id}")->assertForbidden();
        $this->storeApi($a, 'POST', 'auth/switch/'.$this->createStore()->id)->assertForbidden();

        $this->logout();

        // Le lien ne vaut que sur sa boutique, et une seule fois
        $this->get(str_replace($b->slug.'.localhost', $a->slug.'.localhost', $link))->assertRedirect('/gestion/connexion');
        $this->assertGuest('web');
    }

    public function test_a_login_link_works_once(): void
    {
        $a = $this->createStore();
        $b = $this->createStore();
        $this->addMember($b, $a->owner, Role::Manager);

        $this->actingAs($a->owner);
        $link = $this->storeApi($a, 'POST', "auth/switch/{$b->id}")->json('redirect');
        $this->logout();

        $this->get($link)->assertRedirect('/gestion');
        $this->assertAuthenticatedAs($a->owner, 'web');

        $this->logout();
        $this->get($link)->assertRedirect('/gestion/connexion');
        $this->assertGuest('web');
    }

    public function test_the_platform_lists_the_stores_of_an_account(): void
    {
        $a = $this->createStore(['name' => 'Boutique A']);
        $b = $this->createStore(['name' => 'Boutique B', 'status' => StoreStatus::Suspended]);
        $this->addMember($b, $a->owner, Role::Manager);

        $this->platformApi('POST', 'account/login', ['phone' => $a->owner->phone, 'password' => 'mauvais'])->assertStatus(422);

        $stores = $this->platformApi('POST', 'account/login', ['phone' => $a->owner->phone, 'password' => 'password'])
            ->assertOk()->json('data.stores');
        $this->assertSame(['Boutique A', 'Boutique B'], array_column($stores, 'name'));
        $this->assertSame([true, false], array_column($stores, 'is_open'));

        $this->assertStringStartsWith($this->storeUrl($a, '/auth/relais'), $this->platformApi('POST', "account/stores/{$a->id}/open")->assertOk()->json('redirect'));
        $this->platformApi('POST', "account/stores/{$b->id}/open")->assertForbidden();
    }

    public function test_my_account_name_email_and_password(): void
    {
        $store = $this->createStore();
        $cashier = $this->addMember($store, User::factory()->create(['email' => null]), Role::Cashier);
        $this->addMember($store, User::factory()->create(['email' => 'pris@exemple.com']));
        $this->actingAs($cashier);

        // Rapport par email sans adresse : refusé, avec le chemin vers « Mon compte »
        $this->storeApi($store, 'PUT', 'reports/schedule', ['channels' => ['email' => ['is_active' => true]]])
            ->assertStatus(422)->assertJsonPath('message', 'Ajoutez d\'abord votre adresse email (Réglages › Mon compte).');

        // Tout membre, quel que soit son rôle, modifie son nom et son email
        $this->storeApi($store, 'PUT', 'auth/account', ['name' => 'Koffi', 'email' => ' Koffi@Exemple.com '])->assertOk()
            ->assertJsonPath('data.user.name', 'Koffi')->assertJsonPath('data.user.email', 'koffi@exemple.com');
        $this->storeApi($store, 'PUT', 'auth/account', ['name' => 'Koffi', 'email' => 'pris@exemple.com'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Cette adresse email est déjà utilisée par un autre compte.');
        $this->storeApi($store, 'PUT', 'auth/account', ['name' => '', 'email' => 'pas-un-email'])->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);
        $this->storeApi($store, 'PUT', 'reports/schedule', ['channels' => ['email' => ['is_active' => true]]])->assertOk();

        // Email retiré
        $this->storeApi($store, 'PUT', 'auth/account', ['name' => 'Koffi', 'email' => ''])->assertOk()->assertJsonPath('data.user.email', null);

        // Mot de passe : l'actuel est demandé, le nouveau confirmé
        $this->storeApi($store, 'PUT', 'auth/password', ['current_password' => 'mauvais', 'password' => 'nouveau-mdp', 'password_confirmation' => 'nouveau-mdp'])
            ->assertStatus(422)->assertJsonPath('errors.current_password.0', 'Mot de passe actuel incorrect.');
        $this->storeApi($store, 'PUT', 'auth/password', ['current_password' => 'password', 'password' => 'nouveau-mdp', 'password_confirmation' => 'autre'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->storeApi($store, 'PUT', 'auth/password', ['current_password' => 'password', 'password' => 'nouveau-mdp', 'password_confirmation' => 'nouveau-mdp'])->assertOk();
        $this->assertTrue(Hash::check('nouveau-mdp', $cashier->fresh()->password));
    }
}
