<?php

use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Console\ConsoleHealthController;
use App\Models\AdminAction;
use App\Models\CarrierConnection;
use App\Models\CarrierWebhookReceipt;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentWebhookReceipt;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Accounts\ConsoleTwoFactor;
use App\Services\Accounts\StoreDataExport;
use App\Services\Billing\Billing;
use App\Services\Console\SupportSessions;
use App\Services\Couriers\CourierMorning;
use App\Services\Couriers\MissionResponses;
use App\Services\Domains\CustomDomains;
use App\Services\Finance\RecurringExpenses;
use App\Services\Live\LiveBilling;
use App\Services\Live\LiveSales;
use App\Services\Orders\OrderAlerts;
use App\Services\Orders\OrderDesk;
use App\Services\Partners\PartnerReferrals;
use App\Services\Platform\BackupStatus;
use App\Services\Reports\DailyReport;
use App\Services\Shipping\CarrierConnections;
use App\Services\Shipping\CarrierEvents;
use App\Services\Shipping\CarrierException;
use App\Services\Social\SocialPublisher;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\MerchantCatchUp;
use App\Services\WhatsApp\MerchantOnboarding;
use App\Services\WhatsApp\MetaHealth;
use App\Services\WhatsApp\PlatformWhatsApp;
use App\Services\WhatsApp\SignupByWhatsApp;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
// État des numéros WhatsApp officiels chez Meta (compte, nom, qualité, blocages) : chaque numéro relu au plus toutes
// les heures, les nouveaux dans les 10 minutes
Artisan::command('whatsapp:health', function (MetaHealth $health) {
    WhatsappAccount::withoutGlobalScopes()->whereNotNull('phone_number_id')
        ->where(fn ($q) => $q->whereNull('meta_health_at')->orWhere('meta_health_at', '<', now()->subMinutes(MetaHealth::MAX_AGE_MINUTES)))
        ->orderByRaw('meta_health_at IS NOT NULL')->orderBy('meta_health_at')->limit(200)->get()
        ->each(fn (WhatsappAccount $account) => $health->refresh($account));
})->purpose('Relit l\'état des numéros WhatsApp chez Meta');
Schedule::command('whatsapp:health')->everyTenMinutes()->withoutOverlapping()->runInBackground();

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
    $this->line(app(LiveBilling::class)->closeStale().' live(s) resté(s) ouvert(s) trop longtemps fermé(s)');
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

// Dernier recours (seul administrateur, téléphone perdu sans codes de secours) : sur le serveur uniquement
Artisan::command('platform:2fa-reset {phone}', function (string $phone) {
    $user = User::where('phone', PhoneNumber::normalize($phone))->where('is_super_admin', true)->first();
    if ($user === null) {
        $this->error('Aucun administrateur de la console avec ce numéro.');

        return 1;
    }
    app(ConsoleTwoFactor::class)->disable($user);
    DB::table('sessions')->where('user_id', $user->id)->delete();
    AdminAction::create(['admin_id' => $user->id, 'action' => 'console_2fa_reset', 'data' => ['admin' => $user->name, 'by' => 'serveur']]);
    $this->info("Validation en deux étapes retirée pour {$user->name} : à sa prochaine connexion, il recevra un code puis activera une nouvelle application.");
})->purpose('Retire l\'application d\'authentification d\'un administrateur de la console');

// Sauvegardes et disque : les super administrateurs sont prévenus par le WhatsApp du support (une panne au plus une
// fois toutes les 6 heures, un point à surveiller une fois par jour). Tout se voit aussi dans la console › Santé.
Artisan::command('platform:alerts', function (BackupStatus $backups, PlatformWhatsApp $whatsapp) {
    $total = @disk_total_space(base_path()) ?: 0;
    $used = $total ? (int) round(100 - (@disk_free_space(base_path()) ?: 0) / $total * 100) : 0;
    $checks = [
        'nightly' => ['Sauvegarde de la nuit', ...$backups->nightly()],
        'offsite' => ['Copie hors du serveur', ...$backups->offsite()],
        'restore' => ['Test de restauration', ...$backups->restore()],
        'disk' => ['Espace disque', $used >= 90 ? 'error' : ($used >= 80 ? 'warning' : 'ok'), $used.' % utilisé : libérez de la place (anciennes sauvegardes, journaux).'],
    ];
    $new = collect($checks)->filter(fn (array $c, string $key) => match (true) {
        $c[1] === 'error' => Cache::add('platform-alert:'.$key, true, now()->addHours(6)),
        $c[1] === 'warning' && in_array($key, ['restore', 'disk'], true) => Cache::add('platform-warning:'.$key, true, now()->addDay()),
        default => false,
    });
    if ($new->isEmpty()) {
        return;
    }
    $text = "Jibiat Marchand · ⚠️ À vérifier sur la plateforme :\n".$new->map(fn (array $c) => '• '.$c[0].' : '.$c[2])->implode("\n");
    $phones = User::where('is_super_admin', true)->whereNotNull('phone')->pluck('phone')->unique();
    if (! $whatsapp->connected()) {
        $this->warn('WhatsApp du support non relié : alerte non envoyée.');

        return;
    }
    foreach ($phones as $phone) {
        try {
            $whatsapp->sendAutomatic($phone, $text);
        } catch (Throwable $e) {
            report($e);
        }
    }
    $this->info($new->count().' alerte(s) envoyée(s).');
})->purpose('Prévient les super administrateurs d\'une sauvegarde en panne ou d\'un disque presque plein');

Schedule::command('platform:alerts')->everyFifteenMinutes()->withoutOverlapping();
// Tâches en échec gardées 30 jours
Schedule::command('queue:prune-failed --hours=720')->dailyAt('04:40');

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

// Inscription par WhatsApp arrêtée en route : une relance 3 h après (dans les 24 h permises par Meta)
Artisan::command('whatsapp:signups', function (SignupByWhatsApp $signups) {
    if (($count = $signups->remind()) > 0) {
        $this->line("{$count} relance(s)");
    }
})->purpose('Relance une fois les inscriptions par WhatsApp arrêtées en route');

Schedule::command('whatsapp:signups')->everyTenMinutes()->withoutOverlapping();
