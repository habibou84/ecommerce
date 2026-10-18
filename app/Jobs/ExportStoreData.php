<?php

namespace App\Jobs;

use App\Models\StoreExport;
use App\Models\User;
use App\Notifications\ExportReady;
use App\Services\Accounts\StoreDataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Prépare l'archive des données d'une boutique, puis prévient la personne qui l'a demandée.
 */
class ExportStoreData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public readonly int $exportId) {}

    public function handle(StoreDataExport $exports): void
    {
        $export = StoreExport::withoutGlobalScopes()->find($this->exportId);
        if ($export === null || $export->status !== 'pending') {
            return;
        }
        $exports->build($export);
        User::find($export->user_id)?->notify(new ExportReady($export));
    }
}
