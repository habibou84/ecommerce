<?php

use App\Http\Controllers\CaddyAskController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogFeedController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\PaymentPageController;
use App\Http\Controllers\Storefront\ReviewController;
use App\Http\Controllers\Storefront\ShortLinkController;
use App\Http\Controllers\Storefront\StorefrontController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\Webhooks\BillingWebhookController;
use App\Http\Controllers\Webhooks\CarrierWebhookController;
use App\Http\Controllers\Webhooks\MetaWhatsAppWebhookController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Route;

/*
 * Pages publiques, sans session ni jeton CSRF : un visiteur n'a qu'un cookie de panier. Les écritures (panier,
 * commande) passent par des appels JSON de nos propres pages (en-tête X-Requested-With, voir RequireAjax).
 */

// Racine de chaque adresse : présentation (plateforme), boutique, console (voir Tenancy)
Route::get('/', [StorefrontController::class, 'home'])->name('home');
Route::get('/robots.txt', [StorefrontController::class, 'robots']);
// Caddy (même machine) : autorisation d'émettre un certificat pour un nom d'hôte
Route::get('/_caddy/ask', CaddyAskController::class);

Route::middleware('zone:store,custom_domain')->group(function () {
    // Suivi d'une commande par le client (lien envoyé par WhatsApp), même boutique en ligne fermée
    Route::get('/suivi/{token}', TrackingController::class)->where('token', '[A-Za-z0-9]{16,32}')->name('tracking');
    // Avis du client sur les produits reçus (lien envoyé après la livraison)
    Route::get('/avis/{token}', [ReviewController::class, 'show'])->where('token', '[A-Za-z0-9]{16,32}')->name('review');
    Route::post('/avis/{token}', [ReviewController::class, 'store'])->where('token', '[A-Za-z0-9]{16,32}')->middleware(['ajax', 'throttle:storefront-order']);
    // Ticket de caisse (vente au comptoir ou commande payée), à imprimer ou à envoyer par WhatsApp
    Route::get('/recu/{token}', ReceiptController::class)->where('token', '[A-Za-z0-9]{16,32}')->name('receipt');
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

    // Paiement en ligne : lien de paiement, paiement d'une commande, retour du prestataire (boutique ouverte ou non)
    Route::get('/payer/{token}', [PaymentPageController::class, 'link'])->where('token', '[A-Za-z0-9]{16,32}')->name('payment-link');
    Route::get('/paiement/{key}', [PaymentPageController::class, 'result'])->whereUuid('key')->name('payment-result');
    Route::middleware(['ajax', 'throttle:storefront-order'])->group(function () {
        Route::post('/payer/{token}', [PaymentPageController::class, 'payLink'])->where('token', '[A-Za-z0-9]{16,32}');
        Route::post('/commande/{token}/paiement', [PaymentPageController::class, 'payOrder'])->where('token', '[A-Za-z0-9]{16,32}');
    });

    // Aperçu d'une boutique encore fermée (lien signé depuis la gestion)
    Route::get('/apercu', [StorefrontController::class, 'preview'])->name('storefront.preview');

    Route::middleware('storefront.open')->group(function () {
        Route::get('/produits', [StorefrontController::class, 'products'])->name('storefront.products');
        Route::get('/produits/{slug}', [StorefrontController::class, 'product'])->name('storefront.product');
        Route::get('/p/{code}', ShortLinkController::class)->where('code', '[a-z0-9]{4,12}')->name('storefront.short-link');
        Route::get('/collections/{slug}', [StorefrontController::class, 'collection'])->name('storefront.collection');
        Route::get('/pages/{slug}', [StorefrontController::class, 'page'])->name('storefront.page');
        Route::get('/sitemap.xml', [StorefrontController::class, 'sitemap']);
        Route::get('/flux/catalogue/{token}.csv', CatalogFeedController::class)->where('token', '[A-Za-z0-9]{32}')->name('storefront.catalog-feed');
        Route::get('/panier', [CartController::class, 'show'])->name('storefront.cart');
        Route::get('/panier/reprendre/{token}', [CartController::class, 'resume'])->where('token', '[A-Za-z0-9]{40}');
        Route::get('/commande', [CheckoutController::class, 'show'])->name('storefront.checkout');
        Route::get('/commande/merci/{token}', [CheckoutController::class, 'thanks'])->where('token', '[A-Za-z0-9]{16,32}');

        Route::middleware(['ajax', 'throttle:storefront'])->group(function () {
            Route::get('/panier/resume', [CartController::class, 'summary']);
            Route::post('/panier/articles', [CartController::class, 'put']);
            Route::post('/panier/code', [CartController::class, 'code'])->middleware('throttle:storefront-code');
            Route::get('/lieux', [CheckoutController::class, 'places']);
            Route::post('/commande/devis', [CheckoutController::class, 'quote']);
            Route::post('/commande/contact', [CheckoutController::class, 'contact'])->middleware('throttle:storefront-contact');
            Route::post('/commande', [CheckoutController::class, 'place'])->middleware('throttle:storefront-order');
        });
    });
});

// api.jibiat-marchand.com : notifications de Meta pour le WhatsApp des boutiques
Route::middleware(['zone:api', 'throttle:carrier-webhooks'])->group(function () {
    // Application Meta de la plateforme : numéros connectés en un clic
    Route::get('/webhooks/whatsapp', [MetaWhatsAppWebhookController::class, 'verify']);
    Route::post('/webhooks/whatsapp', [MetaWhatsAppWebhookController::class, 'handle'])->name('webhooks.whatsapp.platform');
    Route::get('/webhooks/whatsapp/{uuid}', [WhatsAppWebhookController::class, 'verify'])->whereUuid('uuid');
    Route::post('/webhooks/whatsapp/{uuid}', [WhatsAppWebhookController::class, 'handle'])->whereUuid('uuid')->name('webhooks.whatsapp');
});

// api.jibiat-marchand.com : notifications du prestataire de paiement de la plateforme (abonnements)
Route::match(['get', 'post'], '/webhooks/billing', BillingWebhookController::class)
    ->middleware(['zone:api', 'throttle:carrier-webhooks'])->name('webhooks.billing');

// api.jibiat-marchand.com : notifications des prestataires de paiement
Route::match(['get', 'post'], '/webhooks/payments/{uuid}', PaymentWebhookController::class)
    ->middleware(['zone:api', 'throttle:carrier-webhooks'])->whereUuid('uuid')->name('webhooks.payments');

// api.jibiat-marchand.com : webhooks des entreprises de livraison (signés, sans session)
Route::post('/webhooks/carriers/{uuid}', CarrierWebhookController::class)
    ->middleware(['zone:api', 'throttle:carrier-webhooks'])->whereUuid('uuid')->name('webhooks.carriers');
