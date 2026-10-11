<?php

use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\StorefrontController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\Webhooks\CarrierWebhookController;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Route;

/*
 * Pages publiques, sans session ni jeton CSRF : un visiteur n'a qu'un cookie de panier. Les écritures (panier,
 * commande) passent par des appels JSON de nos propres pages (en-tête X-Requested-With, voir RequireAjax).
 */

// Racine de chaque adresse : présentation (plateforme), boutique, console (voir Tenancy)
Route::get('/', [StorefrontController::class, 'home'])->name('home');
Route::get('/robots.txt', [StorefrontController::class, 'robots']);

Route::middleware('zone:store,custom_domain')->group(function () {
    // Suivi d'une commande par le client (lien envoyé par WhatsApp), même boutique en ligne fermée
    Route::get('/suivi/{token}', TrackingController::class)->where('token', '[A-Za-z0-9]{16,32}')->name('tracking');
    // Application livreur : manifeste (installation sur l'écran d'accueil)
    Route::get('/livreur/manifest.webmanifest', function (Tenancy $tenancy) {
        $store = $tenancy->store();

        return response()->json([
            'name' => 'Livreur · '.$store->name,
            'short_name' => 'Livreur',
            'start_url' => '/livreur',
            'scope' => '/livreur',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#047857',
            'lang' => 'fr',
            'icons' => [['src' => '/livreur-icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable']],
        ])->header('Content-Type', 'application/manifest+json');
    })->middleware('zone:store');

    // Aperçu d'une boutique encore fermée (lien signé depuis la gestion)
    Route::get('/apercu', [StorefrontController::class, 'preview'])->name('storefront.preview');

    Route::middleware('storefront.open')->group(function () {
        Route::get('/produits', [StorefrontController::class, 'products'])->name('storefront.products');
        Route::get('/produits/{slug}', [StorefrontController::class, 'product'])->name('storefront.product');
        Route::get('/collections/{slug}', [StorefrontController::class, 'collection'])->name('storefront.collection');
        Route::get('/pages/{slug}', [StorefrontController::class, 'page'])->name('storefront.page');
        Route::get('/sitemap.xml', [StorefrontController::class, 'sitemap']);
        Route::get('/panier', [CartController::class, 'show'])->name('storefront.cart');
        Route::get('/commande', [CheckoutController::class, 'show'])->name('storefront.checkout');
        Route::get('/commande/merci/{token}', [CheckoutController::class, 'thanks'])->where('token', '[A-Za-z0-9]{16,32}');

        Route::middleware(['ajax', 'throttle:storefront'])->group(function () {
            Route::get('/panier/resume', [CartController::class, 'summary']);
            Route::post('/panier/articles', [CartController::class, 'put']);
            Route::get('/lieux', [CheckoutController::class, 'places']);
            Route::post('/commande/devis', [CheckoutController::class, 'quote']);
            Route::post('/commande/contact', [CheckoutController::class, 'contact']);
            Route::post('/commande', [CheckoutController::class, 'place'])->middleware('throttle:storefront-order');
        });
    });
});

// api.jibiat-marchand.com : webhooks des entreprises de livraison (signés, sans session)
Route::post('/webhooks/carriers/{uuid}', CarrierWebhookController::class)
    ->middleware(['zone:api', 'throttle:carrier-webhooks'])->whereUuid('uuid')->name('webhooks.carriers');
