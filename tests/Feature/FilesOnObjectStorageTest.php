<?php

namespace Tests\Feature;

use App\Support\Files;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fichiers sur un stockage objet (Cloudflare R2, docs/FICHIERS-R2.md) : disque privé choisi par PRIVATE_DISK, copie
 * des fichiers existants avant la bascule, adresse réelle des visiteurs derrière Cloudflare.
 */
class FilesOnObjectStorageTest extends TestCase
{
    public function test_private_files_follow_the_configured_disk(): void
    {
        Storage::fake('local');
        Storage::fake('r2-private');
        $this->assertSame('local', Files::privateDisk());

        config(['filesystems.private_disk' => 'r2-private']);
        Files::private()->put('proofs/1/photo.jpg', 'JPEG');

        Storage::disk('r2-private')->assertExists('proofs/1/photo.jpg');
        Storage::disk('local')->assertMissing('proofs/1/photo.jpg');
    }

    public function test_files_are_copied_once_with_their_paths(): void
    {
        $from = Storage::fake('public');
        $to = Storage::fake('r2');
        $from->put('stores/1/products/a/large.webp', 'image');
        $from->put('aide/video.mp4', 'video');
        $from->put('exports/1/x.zip', 'zip');
        $from->put('.gitignore', '*');

        $this->artisan('files:copy', ['from' => 'public', 'to' => 'r2', '--dry-run' => true])->assertSuccessful();
        $to->assertMissing('stores/1/products/a/large.webp');

        $this->artisan('files:copy', ['from' => 'public', 'to' => 'r2', '--except' => ['exports']])
            ->expectsOutputToContain('2 copié(s)')->assertSuccessful();
        $this->assertSame('image', $to->get('stores/1/products/a/large.webp'));
        $to->assertExists('aide/video.mp4');
        $to->assertMissing('exports/1/x.zip');
        $to->assertMissing('.gitignore');

        // Relancée : rien à refaire
        $this->artisan('files:copy', ['from' => 'public', 'to' => 'r2', '--except' => ['exports']])
            ->expectsOutputToContain('0 copié(s) (0,0 Mo), 2 déjà présent(s)')->assertSuccessful();
    }

    public function test_unknown_disks_are_refused(): void
    {
        $this->artisan('files:copy', ['from' => 'public', 'to' => 'inconnu'])->assertFailed();
    }

    public function test_visitor_address_is_read_behind_cloudflare(): void
    {
        Route::get('/_ip', fn (Request $request) => $request->ip().' '.$request->getScheme());
        $headers = ['X-Forwarded-For' => '41.66.1.2', 'X-Forwarded-Proto' => 'https'];

        // Sans réglage : l'adresse de Cloudflare (tous les visiteurs partageraient les limites de tentatives)
        config(['trustedproxy.proxies' => null]);
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.9'])->get('/_ip', $headers)->assertSeeText('173.245.48.9 http');

        config(['trustedproxy.proxies' => $this->trustedProxiesFor('cloudflare')]);
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.9'])->get('/_ip', $headers)->assertSeeText('41.66.1.2 https');
        // Un inconnu ne peut pas se faire passer pour quelqu'un d'autre
        $this->withServerVariables(['REMOTE_ADDR' => '5.6.7.8'])->get('/_ip', $headers)->assertSeeText('5.6.7.8 http');
    }

    /** Réglage TRUSTED_PROXIES lu par config/trustedproxy.php, quelle que soit la valeur du .env */
    private function trustedProxiesFor(string $value): mixed
    {
        $saved = getenv('TRUSTED_PROXIES');
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;
        putenv("TRUSTED_PROXIES={$value}");

        try {
            return (require base_path('config/trustedproxy.php'))['proxies'];
        } finally {
            unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
            putenv($saved === false ? 'TRUSTED_PROXIES' : "TRUSTED_PROXIES={$saved}");
        }
    }
}
