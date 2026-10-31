<?php

use App\Http\Controllers\BillingValidationController;
use App\Http\Controllers\Console\GmailCallbackController;
use App\Http\Controllers\CourierAccessController;
use App\Http\Controllers\HandoffController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SocialCallbackController;
use App\Support\StartSteps;
use Illuminate\Support\Facades\Route;

// La racine, la boutique en ligne et le suivi des commandes sont dans routes/storefront.php (sans session)

// jibiat-marchand.com : création de boutique et connexion (application Vue)
Route::middleware('zone:platform')->group(function () {
    Route::get('/creer-ma-boutique', [PageController::class, 'spa'])->name('signup');
    Route::get('/connexion', [PageController::class, 'spa'])->name('platform.login');
    // Carte à QR code « clé d'accès » : jibiat-marchand.com/cle/CODE (ou /cle pour saisir le code)
    Route::get('/cle/{code?}', [PageController::class, 'spa'])->where('code', '[A-Za-z0-9-]{1,20}')->name('access-key');
    Route::get('/mot-de-passe-oublie', [PageController::class, 'spa']);
    // Fenêtre Facebook de la connexion WhatsApp des boutiques (Meta n'accepte que des domaines déclarés un par un)
    Route::get('/whatsapp/facebook', [PageController::class, 'spa'])->name('whatsapp.facebook');
    // Écran du live (télé, tablette, ordinateur) : code à 4 chiffres, puis affichage du live de la boutique
    Route::get('/ecran/{token?}', [PageController::class, 'spa'])->where('token', '[A-Za-z0-9]{48}')->name('live-screen');
    // Retour des fenêtres de connexion Facebook / Instagram et TikTok (adresses déclarées chez eux)
    Route::get('/reseaux/{provider}/retour', SocialCallbackController::class)->whereIn('provider', ['meta', 'tiktok'])->name('social.callback');
    // Paiement d'abonnement par lien marchand Wave : lien signé envoyé par SMS au validateur
    Route::get('/facturation/valider/{invoice}', [BillingValidationController::class, 'show'])->whereNumber('invoice')->middleware('signed:relative')->name('billing.validate');
    Route::post('/facturation/valider/{invoice}', [BillingValidationController::class, 'decide'])->whereNumber('invoice')->middleware(['signed:relative', 'throttle:20,1']);
    // Les étapes essentielles pour commencer : une courte vidéo par étape
    Route::view('/bien-demarrer', 'platform.start', ['steps' => StartSteps::all()])->name('platform.start');
    Route::get('/cgu', [LegalController::class, 'terms'])->name('legal.terms');
    Route::get('/confidentialite', [LegalController::class, 'privacy'])->name('legal.privacy');
    Route::get('/suppression-compte', [LegalController::class, 'accountDeletion'])->name('legal.account-deletion');
});

// admin.jibiat-marchand.com : console du super administrateur (application Vue)
Route::middleware('zone:console')->group(function () {
    // Console installable sur l'écran d'accueil (téléphone) ou comme application (ordinateur)
    Route::get('/console/manifest.webmanifest', fn () => response()->json([
        'name' => 'Console Jibiat Marchand',
        'short_name' => 'Console',
        'start_url' => '/console',
        'scope' => '/console',
        'display' => 'standalone',
        'background_color' => '#0f172a',
        'theme_color' => '#0f172a',
        'lang' => 'fr',
        'icons' => [
            ['src' => '/brand/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/brand/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/brand/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ])->header('Content-Type', 'application/manifest+json'))->name('console.manifest');
    // Retour de Google après « Se connecter avec Google » (envoi des e-mails par Gmail)
    Route::get('/console/gmail/retour', GmailCallbackController::class)->middleware('throttle:20,1')->name('console.gmail.callback');
    Route::get('/console/{any?}', [PageController::class, 'spa'])->where('any', '(?!manifest\.webmanifest|gmail/retour).*')->name('console');
});

// {slug}.jibiat-marchand.com : gestion de la boutique (application Vue)
Route::middleware('zone:store')->group(function () {
    Route::get('/auth/relais', HandoffController::class)->name('handoff');
    Route::get('/invitation/{token}', [PageController::class, 'spa'])->name('invitation');
    Route::get('/gestion/{any?}', [PageController::class, 'spa'])->where('any', '(?!manifest\.webmanifest).*')->name('gestion');
    // Lien d'accès du livreur reçu par WhatsApp (un seul usage) : connexion sans mot de passe
    Route::get('/livreur/acces/{token}', CourierAccessController::class)->middleware('throttle:20,1')->name('courier-access');
    // Application des livreurs de la boutique (installable sur le téléphone)
    Route::get('/livreur/{any?}', [PageController::class, 'spa'])->where('any', '(?!manifest\.webmanifest).*')->name('courier-app');
});
