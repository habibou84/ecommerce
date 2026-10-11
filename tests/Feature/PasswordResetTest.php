<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    public function test_a_member_resets_the_password_and_is_logged_in(): void
    {
        $store = $this->createStore();
        $owner = $store->owner;

        $start = $this->storeApi($store, 'POST', 'password/forgot', ['phone' => $owner->phone])->assertStatus(202)->json();
        $this->assertCount(1, $this->sentSms());

        $this->storeApi($store, 'POST', "verifications/{$start['verification']['token']}/confirm", ['code' => $start['demo_code']])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->storeApi($store, 'POST', "verifications/{$start['verification']['token']}/confirm", ['code' => $start['demo_code'], 'password' => 'nouveau-mdp'])
            ->assertOk()->assertJson(['redirect' => '/gestion']);

        $this->assertTrue(Hash::check('nouveau-mdp', $owner->fresh()->password));
        $this->assertAuthenticatedAs($owner, 'web');
    }

    public function test_from_the_platform_the_password_changes_without_login(): void
    {
        $user = User::factory()->create(['phone' => '+2250708091011']);

        $start = $this->platformApi('POST', 'password/forgot', ['phone' => '0708091011'])->json();
        $this->platformApi('POST', "verifications/{$start['verification']['token']}/confirm", ['code' => $start['demo_code'], 'password' => 'nouveau-mdp'])
            ->assertOk()->assertJson(['redirect' => null]);

        $this->assertTrue(Hash::check('nouveau-mdp', $user->fresh()->password));
        $this->assertGuest('web');
    }

    public function test_an_unknown_number_gets_the_same_answer_but_no_sms(): void
    {
        $start = $this->platformApi('POST', 'password/forgot', ['phone' => '0708091011'])->assertStatus(202)->json();

        $this->assertCount(0, $this->sentSms());
        $this->assertNull($start['demo_code']);
        $this->platformApi('POST', "verifications/{$start['verification']['token']}/confirm", ['code' => '123456', 'password' => 'nouveau-mdp'])
            ->assertStatus(422);
    }
}
