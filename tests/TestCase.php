<?php

namespace Tests;

use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounts\ConsoleTwoFactor;
use App\Services\Accounts\StoreRoles;
use App\Services\Accounts\Totp;
use App\Services\Platform\Features;
use App\Support\Tenancy;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Sms\SmsGateway;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages rendues sans les fichiers compilés du front-end
        $this->withoutVite();

        $this->seed([RolesAndPermissionsSeeder::class, PlanSeeder::class, PlacesSeeder::class, ThemeSeeder::class]);

        // Toutes les fonctions allumées : chaque test essaie la sienne (l'extinction a ses propres tests)
        foreach (array_keys(Features::FEATURES) as $key) {
            PlatformSetting::put("feature.{$key}", 'on');
        }
    }

    /**
     * Adresse complète sur la plateforme (localhost) ou une boutique ({slug}.localhost).
     */
    protected function platformUrl(string $path = '/'): string
    {
        return 'http://localhost/'.ltrim($path, '/');
    }

    protected function storeUrl(Store|string $store, string $path = '/'): string
    {
        $slug = $store instanceof Store ? $store->slug : $store;

        return "http://{$slug}.localhost/".ltrim($path, '/');
    }

    /**
     * Appel de l'API depuis la page servie par la même adresse (session Sanctum).
     */
    protected function apiOn(string $baseUrl, string $method, string $path, array $data = []): TestResponse
    {
        return $this->withHeaders(['Referer' => $baseUrl, 'Accept' => 'application/json'])
            ->json($method, rtrim($baseUrl, '/').'/api/v1/'.ltrim($path, '/'), $data);
    }

    /**
     * Premier accès à la console (connecté avec un code) : activation de l'application d'authentification, comme
     * l'écran le fait (QR code puis premier code). Renvoie le secret.
     */
    protected function enableConsoleTwoFactor(): string
    {
        $secret = $this->apiOn('http://admin.localhost', 'POST', 'console/2fa/setup')->assertOk()->json('data.secret');
        $this->apiOn('http://admin.localhost', 'POST', 'console/2fa/confirm', ['code' => Totp::code($secret, Totp::step())])
            ->assertOk()->assertJsonCount(ConsoleTwoFactor::RECOVERY_CODES, 'data.recovery_codes');

        return $secret;
    }

    /** Application d'authentification déjà activée (administrateur de la console pris tel quel dans un test). */
    protected static function consoleTwoFactor(): array
    {
        return ['two_factor_secret' => Totp::secret(), 'two_factor_confirmed_at' => now()];
    }

    /**
     * Connexion à la console d'un administrateur avec son application d'authentification (activée au besoin).
     */
    protected function signInConsole(string $phone = '0701020304', string $password = 'motdepasse'): TestResponse
    {
        $user = User::where('phone', PhoneNumber::normalize($phone))->firstOrFail();
        if (! ConsoleTwoFactor::enabled($user)) {
            $user->forceFill(['two_factor_secret' => Totp::secret(), 'two_factor_confirmed_at' => now()]);
        }
        // Un code ne sert qu'une fois : en test, le dernier pas utilisé est oublié pour se reconnecter
        $user->forceFill(['two_factor_last_step' => null])->save();
        $start = $this->apiOn('http://admin.localhost', 'POST', 'console/auth/login', ['phone' => $phone, 'password' => $password])
            ->assertStatus(202)->assertJsonPath('method', 'totp');

        return $this->apiOn('http://admin.localhost', 'POST', 'console/auth/totp', ['token' => $start->json('token'), 'code' => Totp::code($user->two_factor_secret, Totp::step())])
            ->assertOk();
    }

    /**
     * Appel de la gestion d'une boutique ; une boutique hébergée est choisie à l'adresse de sa boutique hôte.
     */
    protected function storeApi(Store $store, string $method, string $path, array $data = []): TestResponse
    {
        if ($store->host_store_id !== null) {
            return $this->withHeader(Tenancy::STORE_HEADER, (string) $store->id)->apiOn($this->storeUrl($store->host), $method, $path, $data);
        }

        return $this->withoutHeader(Tenancy::STORE_HEADER)->apiOn($this->storeUrl($store), $method, $path, $data);
    }

    protected function platformApi(string $method, string $path, array $data = []): TestResponse
    {
        return $this->apiOn($this->platformUrl(), $method, $path, $data);
    }

    /**
     * Boutique ouverte comme après l'inscription : propriétaire, et essai de l'offre Pro (sans offre : offre gratuite).
     */
    protected function createStore(array $attributes = [], ?string $plan = 'pro'): Store
    {
        $store = Store::factory()->create($attributes);
        $this->addMember($store, $store->owner, Role::Owner);
        if ($plan !== null) {
            $store->subscriptions()->create([
                'plan_id' => Plan::where('code', $plan)->value('id'),
                'status' => SubscriptionStatus::Trialing,
                'trial_ends_at' => now()->addDays((int) config('platform.trial_days')),
                'current_period_start' => now(),
                'current_period_end' => now()->addDays((int) config('platform.trial_days')),
            ]);
        }

        return $store;
    }

    protected function addMember(Store $store, ?User $user = null, Role $role = Role::Manager): User
    {
        $user ??= User::factory()->create();
        $store->members()->updateOrCreate(['user_id' => $user->id], ['status' => UserStatus::Active, 'joined_at' => now()]);
        app(StoreRoles::class)->assign($user, $store, $role);

        return $user;
    }

    /**
     * Déconnexion entre deux requêtes d'un même test. Le middleware auth:sanctum fait de « sanctum » la garde
     * par défaut et elle garde en mémoire la personne de la requête précédente (chaque requête réelle repart de zéro).
     */
    protected function logout(): void
    {
        auth()->guard('web')->logout();
        // Session de la connexion précédente (empreinte du mot de passe vérifiée par AuthenticateSession)
        $this->flushSession();
        config(['auth.defaults.guard' => 'web']);
        $this->app['auth']->forgetGuards();
    }

    /**
     * SMS « envoyés » par le pilote log.
     *
     * @return list<array{to: string, text: string}>
     */
    protected function sentSms(): array
    {
        return app(SmsGateway::class)->sent;
    }
}
