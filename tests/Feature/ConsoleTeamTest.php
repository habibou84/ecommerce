<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ConsoleTeamChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Équipe de la console, mes sessions, recherche d'utilisateurs et santé de la plateforme.
 */
class ConsoleTeamTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse', 'name' => 'Équipe Jibiat', 'email' => 'equipe@jibiat.com']);
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        Notification::fake();
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    private function signIn(): void
    {
        $start = $this->console('POST', 'auth/login', ['phone' => '0701020304', 'password' => 'motdepasse', 'channel' => 'phone'])->assertStatus(202);
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])->assertOk();
    }

    public function test_adding_and_removing_an_administrator_needs_the_password_and_warns_the_team(): void
    {
        $this->console('GET', 'team')->assertUnauthorized();
        $this->signIn();
        $this->console('GET', 'team')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.me', true);

        $new = ['name' => 'Moussa', 'phone' => '0707070707', 'email' => 'moussa@jibiat.com'];
        $this->console('POST', 'team', $new + ['current_password' => 'mauvais'])->assertStatus(422)->assertJsonValidationErrors('current_password');
        // Un compte marchand n'est pas promu administrateur
        $this->console('POST', 'team', ['phone' => $this->store->owner->phone] + $new + ['current_password' => 'motdepasse'])->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->console('POST', 'team', $new + ['current_password' => 'motdepasse'])->assertOk()->assertJsonCount(2, 'data');
        $moussa = User::where('phone', '+2250707070707')->sole();
        $this->assertTrue((bool) $moussa->is_super_admin);
        Notification::assertSentOnDemand(ConsoleTeamChanged::class, fn ($n, $c, $to) => in_array('equipe@jibiat.com', (array) $to->routes['mail'], true));
        $this->assertTrue(AdminAction::where('action', 'console_admin_added')->exists());

        // Premier accès du nouvel administrateur : « Mot de passe oublié » sur la console
        $reset = $this->apiOn(self::CONSOLE, 'POST', 'password/forgot', ['phone' => '0707070707'])->assertStatus(202);
        $this->apiOn(self::CONSOLE, 'POST', "verifications/{$reset->json('verification.token')}/confirm", ['code' => $reset->json('demo_code'), 'password' => 'son-mot-de-passe'])
            ->assertOk()->assertJsonPath('status', 'completed');
        $this->assertTrue(Hash::check('son-mot-de-passe', $moussa->fresh()->password));

        // On ne se retire pas soi-même ; retirer un autre ferme ses sessions
        $this->console('POST', "team/{$this->admin->id}/revoke", ['current_password' => 'motdepasse'])->assertStatus(422)->assertJsonValidationErrors('admin');
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $moussa->id, 'ip_address' => '1.2.3.4', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->console('POST', "team/{$moussa->id}/revoke", ['current_password' => 'mauvais'])->assertStatus(422);
        $this->console('POST', "team/{$moussa->id}/revoke", ['current_password' => 'motdepasse'])->assertOk()->assertJsonCount(1, 'data');
        $this->assertFalse((bool) $moussa->fresh()->is_super_admin);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $moussa->id)->count());
    }

    public function test_my_sessions_and_user_search(): void
    {
        $this->signIn();
        $other = Str::random(40);
        DB::table('sessions')->insert(['id' => $other, 'user_id' => $this->admin->id, 'ip_address' => '5.6.7.8', 'user_agent' => 'Téléphone', 'payload' => '', 'last_activity' => now()->timestamp]);
        $list = $this->console('GET', 'sessions')->assertOk();
        $this->assertContains(hash('sha256', $other), collect($list->json('data'))->pluck('id')->all());
        $this->assertNotContains($other, collect($list->json('data'))->pluck('id')->all());
        $this->console('DELETE', 'sessions/'.hash('sha256', $other))->assertOk();
        $this->assertFalse(DB::table('sessions')->where('id', $other)->exists());

        // Recherche : par numéro, avec les espaces marchands
        $this->console('GET', 'users?q='.urlencode($this->store->owner->phone))->assertOk()
            ->assertJsonPath('data.0.stores.0.name', 'Awa Mode')->assertJsonPath('data.0.stores.0.owner', true);
        $this->console('GET', 'users?q=ab')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_platform_health_and_failed_jobs(): void
    {
        $this->signIn();
        DB::table('failed_jobs')->insert(['uuid' => $uuid = (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ReadLiveCapture']), 'exception' => "RuntimeException: délai dépassé\n#0 trace", 'failed_at' => now()]);

        $this->console('GET', 'health')->assertOk()->assertJsonPath('data.scheduler.ok', false)->assertJsonPath('data.queue.failed', 1)
            ->assertJsonPath('data.failed_jobs.0.job', 'ReadLiveCapture')->assertJsonPath('data.failed_jobs.0.error', 'RuntimeException: délai dépassé');

        $this->artisan('schedule:run');
        $this->console('GET', 'health')->assertJsonPath('data.scheduler.ok', true);

        $this->console('POST', "health/failed-jobs/{$uuid}", ['action' => 'forget'])->assertOk()->assertJsonPath('data.queue.failed', 0);
        $this->assertTrue(AdminAction::where('action', 'failed_job_forget')->exists());
    }
}
