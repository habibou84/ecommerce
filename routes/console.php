<?php

use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Console\ConsoleHealthController;
use App\Models\CarrierConnection;
use App\Models\CarrierWebhookReceipt;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentWebhookReceipt;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Accounts\StoreDataExport;
use App\Services\Billing\Billing;
use App\Services\Console\SupportSessions;
use App\Services\Couriers\CourierMorning;
use App\Services\Couriers\MissionResponses;
use App\Services\Domains\CustomDomains;
use App\Services\Finance\RecurringExpenses;
use App\Services\Live\LiveSales;
use App\Services\Orders\OrderAlerts;
use App\Services\Orders\OrderDesk;
use App\Services\Partners\PartnerReferrals;
use App\Services\Reports\DailyReport;
use App\Services\Shipping\CarrierConnections;
use App\Services\Shipping\CarrierEvents;
use App\Services\Shipping\CarrierException;
use App\Services\Social\SocialPublisher;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\MerchantCatchUp;
use App\Services\WhatsApp\MerchantOnboarding;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
        $this->line("{$connection->name} (espace marchand {$connection->store_id}) : ".$events->sync($connection).' expédition(s) mise(s) à jour');
    }
})->purpose('Synchronise les expéditions avec les entreprises de livraison');

Schedule::command('shipping:sync')->everyTenMinutes()->withoutOverlapping()->runInBackground();

/*
 * Zones des transporteurs relues chaque nuit : une zone ouverte ou fermée chez eux l'est aussi ici, sans attendre
 * que le marchand le fasse à la main.
 */
Artisan::command('shipping:zones', function (CarrierConnections $connections) {
    foreach (CarrierConnection::withoutGlobalScopes()->with('store')->where('driver', CarrierConnection::DELIVERY_APP)->where('status', 'active')->get() as $connection) {
        app(Tenancy::class)->setStore($connection->store);
        try {
            $connections->syncZones($connection);
            $this->line("{$connection->name} (espace marchand {$connection->store_id}) : zones à jour");
        } catch (CarrierException $e) {
            $this->warn("{$connection->name} (espace marchand {$connection->store_id}) : {$e->getMessage()}");
        }
    }
})->purpose('Relit les zones des entreprises de livraison');

Schedule::command('shipping:zones')->dailyAt('03:40')->withoutOverlapping();

/*
 * Missions confiées aux livreurs et toujours sans réponse après un quart d'heure : rappel à l'équipe et au livreur.
 */
Artisan::command('couriers:remind', function (MissionResponses $responses) {
    $this->line($responses->remindUnanswered().' mission(s) sans réponse signalée(s)');
})->purpose('Rappel des missions pas encore acceptées par les livreurs');

Schedule::command('couriers:remind')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

// Résumé du matin des livreurs : courses du jour et argent à encaisser
Artisan::command('couriers:morning', function (CourierMorning $morning) {
    $this->info($morning->send().' livreur(s) prévenu(s).');
})->purpose('Envoie à chaque livreur le résumé de ses courses du jour');
Schedule::command('couriers:morning')->dailyAt('07:05')->withoutOverlapping();

/*
 * Commandes qui attendent (sans livreur, pas ramassées, à confirmer, point du matin...) : alertes à l'équipe, une
 * seule fois chacune, pendant les heures d'alerte de la boutique.
 */
Artisan::command('orders:alerts', function (OrderAlerts $alerts) {
    $this->line($alerts->run().' alerte(s) envoyée(s)');
})->purpose('Alertes sur les commandes qui attendent');

Schedule::command('orders:alerts')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

/*
 * Publications programmées sur les réseaux sociaux, arrivées à l'heure.
 */
Artisan::command('social:publish', function (SocialPublisher $publisher) {
    $this->line($publisher->publishDue().' publication(s) programmée(s) publiée(s)');
})->purpose('Publie sur les réseaux les produits programmés');

Schedule::command('social:publish')->everyMinute()->withoutOverlapping()->runInBackground();

/*
 * Paniers abandonnés : un client a laissé son numéro et des articles sans commander depuis plus d'une heure (et moins
 * de deux jours) ; une relance WhatsApp part si la boutique l'a activée. Un seul message par panier.
 */
Artisan::command('carts:recover', function (CustomerMessenger $messenger) {
    $carts = Cart::withoutGlobalScopes()->where('status', 'active')->whereNotNull('phone')->whereNull('recovery_sent_at')
        ->whereBetween('last_activity_at', [now()->subDays(2), now()->subHour()])->whereHas('items')->get();

    // Contre les relances en masse vers des numéros inventés : une seule par numéro et par boutique sur 7 jours, et
    // au plus 20 par boutique à chaque passage
    $perHour = 20;
    $sent = 0;
    $perStore = [];
    foreach ($carts as $cart) {
        $recent = Cart::withoutGlobalScopes()->where('store_id', $cart->store_id)->where('phone', $cart->phone)
            ->where('recovery_sent_at', '>=', now()->subDays(7))->exists();
        if ($recent || ($perStore[$cart->store_id] ?? 0) >= $perHour) {
            continue;
        }
        app(Tenancy::class)->setStore($cart->store);
        if ($messenger->forCart($cart)) {
            $perStore[$cart->store_id] = ($perStore[$cart->store_id] ?? 0) + 1;
            $cart->forceFill(['recovery_sent_at' => now()])->save();
            $sent++;
        }
    }
    $this->line("{$sent} relance(s) envoyée(s)");
})->purpose('Relance par WhatsApp des paniers abandonnés');

Schedule::command('carts:recover')->hourly()->withoutOverlapping()->runInBackground();

/*
 * Commandes passées en ligne et jamais confirmées : annulées au bout de 48 h pour rendre le stock (sauf payées ou
 * avec un rappel prévu plus tard). Sans cela, de fausses commandes bloqueraient des articles indéfiniment.
 */
Artisan::command('orders:expire', function (OrderDesk $desk) {
    $hours = 48;
    $expired = Order::withoutGlobalScopes()->with('store')->where('source', 'online_store')->where('status', OrderStatus::PendingConfirmation->value)
        ->where('created_at', '<', now()->subHours($hours))->where('amount_paid', 0)
        ->where(fn ($q) => $q->whereNull('callback_at')->orWhere('callback_at', '<', now()))->get();

    foreach ($expired as $order) {
        app(Tenancy::class)->setStore($order->store);
        $desk->cancel($order, CancelReason::Unreachable, "Annulée automatiquement : pas confirmée en {$hours} h (stock rendu).", null);
    }
    $this->line($expired->count().' commande(s) non confirmée(s) annulée(s)');
})->purpose('Annule les commandes en ligne jamais confirmées');

Schedule::command('orders:expire')->hourly()->withoutOverlapping()->runInBackground();

Artisan::command('reports:send', function (DailyReport $reports) {
    $this->line($reports->sendDue().' rapport(s) envoyé(s)');
})->purpose('Rapports quotidiens à l\'équipe (WhatsApp ou email), à l\'heure choisie');

Schedule::command('reports:send')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();

Artisan::command('expenses:recurring', function (RecurringExpenses $expenses) {
    $this->info($expenses->run().' dépense(s) mensuelle(s) enregistrée(s).');
})->purpose('Enregistrer les dépenses mensuelles arrivées à échéance');
Schedule::command('expenses:recurring')->hourly()->withoutOverlapping();

Artisan::command('exports:purge', function (StoreDataExport $exports) {
    $this->info($exports->purge().' export(s) expiré(s) supprimé(s).');
})->purpose('Supprimer les exports de données de plus de 7 jours');
Schedule::command('exports:purge')->dailyAt('04:10');

// Données personnelles gardées le temps utile : reçus de webhooks et paniers abandonnés, effacés après 90 jours
Schedule::command('model:prune', ['--model' => [PaymentWebhookReceipt::class, CarrierWebhookReceipt::class, Cart::class]])->dailyAt('04:20');

Artisan::command('billing:run', function (Billing $billing) {
    $counts = $billing->run();
    $this->line("Rappels : {$counts['reminded']} · essais terminés : {$counts['trials_ended']} · en retard : {$counts['past_due']} · retour à l'offre gratuite : {$counts['downgraded']}");
})->purpose('Abonnements : rappels, fins d\'essai, échéances et retards');

Schedule::command('billing:run')->dailyAt('07:10')->withoutOverlapping();

Artisan::command('live:expire', function (LiveSales $live) {
    $this->line($live->expire().' réservation(s) de live libérée(s)');
})->purpose('Vente en live : pièces gardées sans quartier à temps rendues à la liste d\'attente');

Schedule::command('live:expire')->everyMinute()->withoutOverlapping();

Artisan::command('referrals:report', function (PartnerReferrals $referrals) {
    $sent = Store::whereNotNull('referred_by')->get()->filter(fn (Store $store) => $referrals->push($store))->count();
    $this->line("{$sent} boutique(s) parrainée(s) envoyée(s) à leur entreprise de livraison");
})->purpose('Parrainage : abonnement et part gagnée envoyés chaque jour aux entreprises de livraison');

Schedule::command('referrals:report')->dailyAt('07:40')->withoutOverlapping();

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
        $this->error('Aucun compte avec ce numéro (créez-le d\'abord en créant un espace marchand ou par invitation).');

        return 1;
    }
    $user->forceFill(['is_super_admin' => ! $this->option('revoke')])->save();
    $this->info($user->name.($this->option('revoke') ? ' n\'a plus accès' : ' a accès').' à la console.');
})->purpose('Donne (ou retire) l\'accès à la console du super administrateur');

// Battement du planificateur : la console (Santé de la plateforme) voit qu'il tourne bien
Schedule::call(fn () => Cache::put(ConsoleHealthController::HEARTBEAT, now()->toIso8601String(), now()->addDay()))->everyMinute()->name('scheduler-heartbeat');

/*
 * Accueil des nouveaux commerçants par le WhatsApp du support : bienvenue, premier produit, vente en live, fin de
 * l'essai (MerchantOnboarding). Rien entre 21 h et 7 h.
 */
Artisan::command('onboarding:run', function (MerchantOnboarding $onboarding, MerchantCatchUp $catchUp) {
    foreach ($onboarding->run() as $step => $count) {
        if ($count > 0) {
            $this->line("{$step} : {$count} message(s)");
        }
    }
    // Rattrapage des commerçants déjà inscrits, quand il est lancé depuis la console
    if (($count = $catchUp->run()) > 0) {
        $this->line("rattrapage : {$count} message(s)");
    }
})->purpose('Envoie les messages WhatsApp d\'accueil des nouveaux commerçants');

Schedule::command('onboarding:run')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
