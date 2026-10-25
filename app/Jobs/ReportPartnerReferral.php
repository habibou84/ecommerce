<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Partners\PartnerReferrals;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Compte de la boutique envoyé à l'entreprise de livraison qui l'a parrainée (abonnement, part gagnée), hors de
 * la requête. Le rapport quotidien (referrals:report) rattrape un envoi manqué.
 */
class ReportPartnerReferral implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $storeId) {}

    public function handle(PartnerReferrals $referrals): void
    {
        $store = Store::find($this->storeId);
        if ($store !== null && filled($store->referred_by)) {
            $referrals->push($store);
        }
    }
}
