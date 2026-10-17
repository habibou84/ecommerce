<?php

use App\Models\CarrierConnection;
use App\Models\Cart;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Billing\Billing;
use App\Services\Console\SupportSessions;
use App\Services\Domains\CustomDomains;
use App\Services\Finance\RecurringExpenses;
use App\Services\Reports\DailyReport;
use App\Services\Shipping\CarrierEvents;
use App\Services\WhatsApp\CustomerMessenger;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Jibiat\Socle\PhoneNumber;

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

Artisan::command('expenses:recurring', function (RecurringExpenses $expenses) {
    $this->info($expenses->run().' dépense(s) mensuelle(s) enregistrée(s).');
})->purpose('Enregistrer les dépenses mensuelles arrivées à échéance');
Schedule::command('expenses:recurring')->hourly()->withoutOverlapping();

Artisan::command('billing:run', function (Billing $billing) {
    $counts = $billing->run();
    $this->line("Rappels : {$counts['reminded']} · essais terminés : {$counts['trials_ended']} · en retard : {$counts['past_due']} · retour à l'offre gratuite : {$counts['downgraded']}");
})->purpose('Abonnements : rappels, fins d\'essai, échéances et retards');

Schedule::command('billing:run')->dailyAt('07:10')->withoutOverlapping();

Artisan::command('domains:check', function (CustomDomains $domains) {
    $count = 0;
    foreach (StoreDomain::with('store')->get() as $domain) {
        $domains->verify($domain);
        $count++;
    }
    $this->line("{$count} domaine(s) vérifié(s)");
})->purpose('Contrôle DNS des domaines personnalisés des boutiques');

Schedule::command('domains:check')->dailyAt('03:20')->withoutOverlapping();

Artisan::command('support:expire', function (SupportSessions $sessions) {
    $this->line($sessions->expireDue().' session(s) de support terminée(s)');
})->purpose('Fin des sessions de support arrivées à échéance');

Schedule::command('support:expire')->everyFiveMinutes();

Artisan::command('platform:admin {phone} {--revoke}', function (string $phone) {
    $user = User::where('phone', PhoneNumber::normalize($phone))->first();
    if ($user === null) {
        $this->error('Aucun compte avec ce numéro (créez-le d\'abord en ouvrant une boutique ou par invitation).');

        return 1;
    }
    $user->forceFill(['is_super_admin' => ! $this->option('revoke')])->save();
    $this->info($user->name.($this->option('revoke') ? ' n\'a plus accès' : ' a accès').' à la console.');
})->purpose('Donne (ou retire) l\'accès à la console du super administrateur');
