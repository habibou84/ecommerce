<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounts\StoreRoles;
use Tests\TestCase;

class SignupTest extends TestCase
{
    private function signupData(array $overrides = []): array
    {
        return array_merge([
            'store_name' => 'Awa Mode',
            'slug' => 'awa-mode',
            'name' => 'Awa Koné',
            'phone' => '07 08 09 10 11',
            'password' => 'motdepasse',
        ], $overrides);
    }

    public function test_slug_availability_is_checked_while_typing(): void
    {
        $this->createStore(['slug' => 'prise']);

        $this->platformApi('GET', 'signup/slug?slug=libre')->assertOk()
            ->assertJson(['available' => true, 'url' => 'http://libre.localhost/']);
        $this->platformApi('GET', 'signup/slug?slug=prise')->assertJson(['available' => false, 'message' => 'Cette adresse est déjà prise.']);
        $this->platformApi('GET', 'signup/slug?slug=admin')->assertJson(['available' => false, 'message' => 'Cette adresse est réservée.']);
        $this->platformApi('GET', 'signup/slug?slug=Ab')->assertJson(['available' => false]);
        $this->platformApi('GET', 'signup/slug?slug=-awa')->assertJson(['available' => false]);
        $this->platformApi('GET', 'signup/slug?slug=awa--mode')->assertJson(['available' => false]);
    }

    public function test_a_new_merchant_creates_a_store_after_verifying_the_phone(): void
    {
        $start = $this->platformApi('POST', 'signup', $this->signupData())->assertStatus(202)
            ->assertJsonPath('verification.phone', '07 08 09 10 11');
        $token = $start->json('verification.token');
        $code = $start->json('demo_code');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertCount(1, $this->sentSms());
        $this->assertSame('+2250708091011', $this->sentSms()[0]['to']);
        $this->assertStringContainsString($code, $this->sentSms()[0]['text']);
        $this->assertDatabaseMissing('users', ['phone' => '+2250708091011']);

        $this->platformApi('POST', "verifications/{$token}/confirm", ['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');

        $redirect = $this->platformApi('POST', "verifications/{$token}/confirm", ['code' => $code])->assertCreated()->json('redirect');
        $this->assertStringStartsWith('http://awa-mode.localhost/auth/relais?jeton=', $redirect);

        $store = Store::where('slug', 'awa-mode')->firstOrFail();
        $user = User::where('phone', '+2250708091011')->firstOrFail();
        $this->assertSame($user->id, $store->owner_id);
        $this->assertSame(StoreStatus::Trial, $store->status);
        $this->assertSame('+2250708091011', $store->whatsapp_phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(Role::Owner, app(StoreRoles::class)->roleOf($user, $store));

        $subscription = $store->currentSubscription()->with('plan')->first();
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame('pro', $subscription->plan->code);
        $this->assertTrue($subscription->trial_ends_at->between(now()->addDays(29), now()->addDays(31)));

        // Le lien de connexion ouvre la gestion de la nouvelle boutique
        $this->get($redirect)->assertRedirect('/gestion?bienvenue=1');
        $this->assertAuthenticatedAs($user, 'web');
        $this->storeApi($store, 'GET', 'auth/me')->assertOk()
            ->assertJsonPath('data.role.value', 'owner')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.store.slug', 'awa-mode');

        // Le code ne sert qu'une fois
        $this->platformApi('POST', "verifications/{$token}/confirm", ['code' => $code])->assertStatus(422);
    }

    public function test_an_existing_account_creates_another_store_with_its_password(): void
    {
        $user = User::factory()->create(['phone' => '+2250708091011']);

        $this->platformApi('POST', 'signup', $this->signupData(['password' => 'mauvais-mdp']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $redirect = $this->platformApi('POST', 'signup', $this->signupData(['password' => 'password']))
            ->assertCreated()->json('redirect');

        $this->assertStringStartsWith('http://awa-mode.localhost/auth/relais', $redirect);
        $this->assertSame($user->id, Store::where('slug', 'awa-mode')->value('owner_id'));
        $this->assertCount(0, $this->sentSms());
    }

    public function test_signup_validates_its_fields(): void
    {
        $this->createStore(['slug' => 'prise']);

        $this->platformApi('POST', 'signup', $this->signupData(['slug' => 'prise', 'phone' => 'abc', 'password' => 'court']))
            ->assertStatus(422)->assertJsonValidationErrors(['slug', 'phone', 'password']);
    }

    public function test_only_ivorian_mobiles_receive_codes_for_now(): void
    {
        $this->platformApi('POST', 'signup', $this->signupData(['phone' => '+33612345678']))
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->platformApi('POST', 'signup', $this->signupData(['phone' => '27 22 44 55 66']))
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_a_slug_taken_during_verification_is_reported(): void
    {
        $start = $this->platformApi('POST', 'signup', $this->signupData())->json();
        $this->createStore(['slug' => 'awa-mode']);

        $this->platformApi('POST', "verifications/{$start['verification']['token']}/confirm", ['code' => $start['demo_code']])
            ->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->assertDatabaseMissing('users', ['phone' => '+2250708091011']);
    }

    public function test_signup_and_its_verification_only_exist_on_the_platform_address(): void
    {
        $store = $this->createStore();
        $start = $this->platformApi('POST', 'signup', $this->signupData())->json();

        $this->storeApi($store, 'POST', 'signup', $this->signupData(['slug' => 'autre']))->assertNotFound();
        $this->storeApi($store, 'POST', "verifications/{$start['verification']['token']}/confirm", ['code' => $start['demo_code']])->assertNotFound();
    }

    public function test_codes_are_rate_limited(): void
    {
        $start = $this->platformApi('POST', 'signup', $this->signupData())->json();
        $token = $start['verification']['token'];

        // Nouveau code : attendre une minute
        $this->platformApi('POST', "verifications/{$token}/resend")->assertStatus(422);
        $this->travel(61)->seconds();
        $this->platformApi('POST', "verifications/{$token}/resend")->assertOk();

        // Cinq essais au plus
        foreach (range(1, 5) as $i) {
            $this->platformApi('POST', "verifications/{$token}/confirm", ['code' => '000000'])->assertStatus(422);
        }
        $this->platformApi('POST', "verifications/{$token}/confirm", ['code' => '000000'])
            ->assertStatus(422)->assertJsonPath('message', 'Trop d\'essais. Demandez un nouveau code.');
    }
}
