<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Services\Billing\BillingSettings;
use App\Services\Mail\GoogleMail;
use App\Services\WhatsApp\PlatformWhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Santé de la plateforme, en lecture : tâches planifiées, file d'attente et tâches en échec, espace disque, envoi des
 * e-mails et des SMS, WhatsApp du support, versions. Seule action : relancer ou effacer une tâche en échec (journalisé).
 * Aucun secret n'est affiché.
 */
class ConsoleHealthController extends Controller
{
    /** Clé posée chaque minute par le planificateur (routes/console.php) */
    public const HEARTBEAT = 'scheduler:heartbeat';

    public function show(BillingSettings $billing, GoogleMail $gmail, PlatformWhatsApp $whatsapp): JsonResponse
    {
        $heartbeat = Cache::get(self::HEARTBEAT);
        $total = @disk_total_space(base_path()) ?: null;
        $free = @disk_free_space(base_path()) ?: null;
        $failed = DB::table('failed_jobs')->orderByDesc('id')->limit(30)->get();

        return response()->json(['data' => [
            'scheduler' => [
                'last_run' => $heartbeat,
                // Le planificateur tourne chaque minute : au-delà de 5 minutes sans passage, il est arrêté
                'ok' => $heartbeat !== null && Carbon::parse($heartbeat)->gt(now()->subMinutes(5)),
            ],
            'queue' => [
                'connection' => config('queue.default'),
                'pending' => config('queue.default') === 'database' ? DB::table('jobs')->count() : null,
                'oldest_pending' => config('queue.default') === 'database' ? optional(DB::table('jobs')->min('created_at'), fn ($t) => now()->setTimestamp((int) $t)->toIso8601String()) : null,
                'failed' => DB::table('failed_jobs')->count(),
            ],
            'failed_jobs' => $failed->map(fn ($j) => [
                'uuid' => $j->uuid,
                'job' => class_basename((string) (json_decode((string) $j->payload, true)['displayName'] ?? 'Tâche')),
                'queue' => $j->queue,
                // Première ligne de l'erreur seulement : la trace complète reste dans le journal du serveur
                'error' => Str::limit(strtok((string) $j->exception, "\n") ?: '', 300),
                'failed_at' => Carbon::parse($j->failed_at)->toIso8601String(),
            ]),
            'disk' => $total && $free ? ['total' => (int) $total, 'free' => (int) $free, 'used_percent' => (int) round(100 - $free / $total * 100)] : null,
            'mail' => ['ready' => $billing->mailReady(), 'gmail' => $gmail->connected() ? $gmail->email() : null],
            'sms' => ['ready' => $billing->smsReady()],
            'whatsapp' => ['connected' => $whatsapp->connected()],
            'versions' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'environment' => app()->environment()],
        ]]);
    }

    /**
     * Relance (« retry ») ou efface (« forget ») une tâche en échec.
     */
    public function failedJob(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:retry,forget']]);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call($data['action'] === 'retry' ? 'queue:retry' : 'queue:forget', $data['action'] === 'retry' ? ['id' => [$uuid]] : ['id' => $uuid]);
        AdminAction::create(['admin_id' => $request->user()->id, 'action' => 'failed_job_'.$data['action'], 'data' => ['uuid' => $uuid], 'ip' => $request->ip()]);

        return $this->show(app(BillingSettings::class), app(GoogleMail::class), app(PlatformWhatsApp::class));
    }
}
