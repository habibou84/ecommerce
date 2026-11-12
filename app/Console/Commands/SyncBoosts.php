<?php

namespace App\Console\Commands;

use App\Models\Boost;
use App\Services\Marketing\BoostAds;
use Illuminate\Console\Command;

/**
 * Boosts en cours : état de la pub chez Meta (vérification, en ligne, refusée, terminée), dépense et clics, commandes
 * venues de la pub et prix de l'assistant retiré du crédit. Les boosts finis depuis moins de 2 jours sont relus une
 * dernière fois (commandes arrivées après la fin).
 */
class SyncBoosts extends Command
{
    protected $signature = 'boosts:sync';

    protected $description = 'Relit chez Meta les boosts en cours et compte leurs commandes';

    public function handle(BoostAds $ads): int
    {
        Boost::withoutGlobalScopes()->with('store')
            ->where(fn ($q) => $q->whereIn('status', Boost::LIVE)->orWhere(fn ($q) => $q->where('status', 'ended')->where('ends_at', '>=', now()->subDays(2))))
            ->orderBy('id')->each(function (Boost $boost) use ($ads) {
                if ($boost->store) {
                    $ads->sync($boost);
                }
            });

        return self::SUCCESS;
    }
}
