<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WhatsApp\PlatformWhatsApp;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Sauvegardes (états écrits par deploy/backup.sh) dans la console › Santé, et alerte WhatsApp des super administrateurs.
 */
class BackupHealthTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private string $dir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/jibiat-marchand-backups-'.uniqid();
        File::makeDirectory($this->dir);
        config(['platform.backup_path' => $this->dir]);
        $this->admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse', 'name' => 'Équipe Jibiat']);
        $this->admin->forceFill(['is_super_admin' => true])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $data */
    private function state(string $name, array $data, string $ago = '1 hour'): void
    {
        File::put($this->dir.'/etat-'.$name.'.json', json_encode(['at' => now()->sub($ago)->utc()->format('Y-m-d\TH:i:s\Z'), ...$data]));
    }

    private bool $signedIn = false;

    private function health(): TestResponse
    {
        if (! $this->signedIn) {
            $this->signIn();
        }

        return $this->apiOn(self::CONSOLE, 'GET', 'console/health')->assertOk();
    }

    private function signIn(): void
    {
        $this->signedIn = true;
        $start = $this->apiOn(self::CONSOLE, 'POST', 'console/auth/login', ['phone' => '0701020304', 'password' => 'motdepasse', 'channel' => 'phone'])->assertStatus(202);
        $this->apiOn(self::CONSOLE, 'POST', 'console/auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])->assertOk();
    }

    public function test_backup_states_are_shown_in_the_console(): void
    {
        $backups = collect($this->health()->json('data.backups'))->keyBy('key');
        $this->assertSame(['error', 'ok', 'warning'], [$backups['nightly']['status'], $backups['offsite']['status'], $backups['restore']['status']]);

        $this->state('sauvegarde', ['ok' => true, 'base' => 'base-2026-11-03.dump', 'base_bytes' => 8 * 1024 ** 2, 'files_bytes' => 2 * 1024 ** 3, 'copies' => 3]);
        $this->state('restauration', ['ok' => true, 'backup' => 'base-2026-11-02.dump', 'counts' => ['orders' => [5400, 5420], 'stores' => [61, 61]]], '1 day');
        $backups = collect($this->health()->json('data.backups'))->keyBy('key');
        $this->assertSame('ok', $backups['nightly']['status']);
        $this->assertStringContainsString('base 8 Mo, fichiers 2 Go, 3 copie(s)', $backups['nightly']['detail']);
        $this->assertStringContainsString('5400 commande(s), 61 boutique(s)', $backups['restore']['detail']);

        $this->state('sauvegarde', ['ok' => true, 'base_bytes' => 1, 'files_bytes' => 1], '27 hours');
        $this->assertSame('error', collect($this->health()->json('data.backups'))->keyBy('key')['nightly']['status']);
    }

    public function test_offsite_copy_is_expected_in_production(): void
    {
        $this->signIn();
        $this->app['env'] = 'production';
        $backups = collect($this->health()->json('data.backups'))->keyBy('key');
        $this->assertSame('warning', $backups['offsite']['status']);

        $this->state('distant', ['ok' => false, 'message' => 'Envoi impossible : accès refusé']);
        $offsite = collect($this->health()->json('data.backups'))->keyBy('key')['offsite'];
        $this->assertSame('error', $offsite['status']);
        $this->assertStringContainsString('accès refusé', $offsite['detail']);
    }

    public function test_super_admins_are_warned_by_whatsapp_once(): void
    {
        $this->state('sauvegarde', ['ok' => false, 'message' => 'La sauvegarde de la nuit a échoué.']);
        $whatsapp = Mockery::mock(PlatformWhatsApp::class);
        $whatsapp->shouldReceive('connected')->andReturnTrue();
        $whatsapp->shouldReceive('sendAutomatic')->once()->withArgs(fn (string $to, string $text) => $to === '+2250701020304'
            && str_contains($text, 'Sauvegarde de la nuit : La sauvegarde de la nuit a échoué.'));
        $this->app->instance(PlatformWhatsApp::class, $whatsapp);

        $this->artisan('platform:alerts')->assertSuccessful();
        // Même panne : pas de nouveau message avant 6 heures
        $this->artisan('platform:alerts')->assertSuccessful();
    }
}
