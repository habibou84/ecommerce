<?php

use App\Models\CarrierConnection;
use App\Services\Shipping\CarrierEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Filet de sécurité des transporteurs : toutes les 10 minutes, les courses modifiées sont relues pour rattraper un
 * webhook perdu (docs/PLAN.md §5.4).
 */
Artisan::command('shipping:sync', function (CarrierEvents $events) {
    $connections = CarrierConnection::withoutGlobalScopes()->with('store')
        ->where('driver', CarrierConnection::DELIVERY_APP)->where('status', 'active')->get();

    foreach ($connections as $connection) {
        $this->line("{$connection->name} (boutique {$connection->store_id}) : ".$events->sync($connection).' expédition(s) mise(s) à jour');
    }
})->purpose('Synchronise les expéditions avec les entreprises de livraison');

Schedule::command('shipping:sync')->everyTenMinutes()->withoutOverlapping();
