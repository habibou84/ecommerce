<?php

use App\Http\Controllers\BillingValidationController;
use App\Http\Controllers\HandoffController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SocialCallbackController;
use Illuminate\Support\Facades\Route;

// La racine, la boutique en ligne et le suivi des commandes sont dans routes/storefront.php (sans session)

// jibiat-marchand.com : création de boutique et connexion (application Vue)
Route::middleware('zone:platform')->group(function () {
    Route::get('/creer-ma-boutique', [PageController::class, 'spa'])->name('signup');
    Route::get('/connexion', [PageController::class, 'spa'])->name('platform.login');
    Route::get('/mot-de-passe-oublie', [PageController::class, 'spa']);
    // Fenêtre Facebook de la connexion WhatsApp des boutiques (Meta n'accepte que des domaines déclarés un par un)
    Route::get('/whatsapp/facebook', [PageController::class, 'spa'])->name('whatsapp.facebook');
    // Retour des fenêtres de connexion Facebook / Instagram et TikTok (adresses déclarées chez eux)
    Route::get('/reseaux/{provider}/retour', SocialCallbackController::class)->whereIn('provider', ['meta', 'tiktok'])->name('social.callback');
    // Paiement d'abonnement par lien marchand Wave : lien signé envoyé par SMS au validateur
    Route::get('/facturation/valider/{invoice}', [BillingValidationController::class, 'show'])->whereNumber('invoice')->middleware('signed:relative')->name('billing.validate');
    Route::post('/facturation/valider/{invoice}', [BillingValidationController::class, 'decide'])->whereNumber('invoice')->middleware(['signed:relative', 'throttle:20,1']);
    Route::get('/cgu', [LegalController::class, 'terms'])->name('legal.terms');
    Route::get('/confidentialite', [LegalController::class, 'privacy'])->name('legal.privacy');
    Route::get('/suppression-compte', [LegalController::class, 'accountDeletion'])->name('legal.account-deletion');
});

// admin.jibiat-marchand.com : console du super administrateur (application Vue)
Route::middleware('zone:console')->group(function () {
    Route::get('/console/{any?}', [PageController::class, 'spa'])->where('any', '.*')->name('console');
});

// {slug}.jibiat-marchand.com : gestion de la boutique (application Vue)
Route::middleware('zone:store')->group(function () {
    Route::get('/auth/relais', HandoffController::class)->name('handoff');
    Route::get('/invitation/{token}', [PageController::class, 'spa'])->name('invitation');
    Route::get('/gestion/{any?}', [PageController::class, 'spa'])->where('any', '(?!manifest\.webmanifest).*')->name('gestion');
    // Application des livreurs de la boutique (installable sur le téléphone)
    Route::get('/livreur/{any?}', [PageController::class, 'spa'])->where('any', '(?!manifest\.webmanifest).*')->name('courier-app');
});
