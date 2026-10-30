<?php

namespace App\Jobs;

use App\Models\WhatsappAccount;
use App\Services\WhatsApp\TemplateLibrary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Modèles WhatsApp ajoutés depuis la connexion du numéro, soumis à Meta en arrière-plan.
 */
class InstallMissingTemplates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $accountId) {}

    public function handle(TemplateLibrary $templates): void
    {
        $account = WhatsappAccount::withoutGlobalScopes()->find($this->accountId);
        if ($account !== null) {
            $templates->installMissing($account);
        }
    }
}
