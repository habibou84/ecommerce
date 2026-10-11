<?php

use App\Models\CarrierConnection;
use App\Models\Cart;
use App\Services\Reports\DailyReport;
use App\Services\Shipping\CarrierEvents;
use App\Services\WhatsApp\CustomerMessenger;
use App\Support\Tenancy;
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

/*
 * Paniers abandonnés : un client a laissé son numéro et des articles sans commander depuis plus d'une heure (et moins
 * de deux jours) ; une relance WhatsApp part si la boutique l'a activée. Un seul message par panier.
 */
Artisan::command('carts:recover', function (CustomerMessenger $messenger) {
    $carts = Cart::withoutGlobalScopes()->where('status', 'active')->whereNotNull('phone')->whereNull('recovery_sent_at')
        ->whereBetween('last_activity_at', [now()->subDays(2), now()->subHour()])->whereHas('items')->get();

    $sent = 0;
    foreach ($carts as $cart) {
        app(Tenancy::class)->setStore($cart->store);
        if ($messenger->forCart($cart)) {
            $cart->forceFill(['recovery_sent_at' => now()])->save();
            $sent++;
        }
    }
    $this->line("{$sent} relance(s) envoyée(s)");
})->purpose('Relance par WhatsApp des paniers abandonnés');

Schedule::command('carts:recover')->hourly()->withoutOverlapping();

Artisan::command('reports:send', function (DailyReport $reports) {
    $this->line($reports->sendDue().' rapport(s) envoyé(s)');
})->purpose('Rapports quotidiens à l\'équipe (WhatsApp ou email), à l\'heure choisie');

Schedule::command('reports:send')->everyFifteenMinutes()->withoutOverlapping();
