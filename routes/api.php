<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Catalog\CatalogTransferController;
use App\Http\Controllers\Api\V1\Catalog\CollectionController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ProductImageController;
use App\Http\Controllers\Api\V1\Catalog\TagController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Orders\CustomerController;
use App\Http\Controllers\Api\V1\Orders\DeliveryController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PlatformAccountController;
use App\Http\Controllers\Api\V1\Shipping\CarrierController;
use App\Http\Controllers\Api\V1\Shipping\ShipmentController;
use App\Http\Controllers\Api\V1\SignupController;
use App\Http\Controllers\Api\V1\Stock\CountController;
use App\Http\Controllers\Api\V1\Stock\LevelController;
use App\Http\Controllers\Api\V1\Stock\LocationController;
use App\Http\Controllers\Api\V1\Stock\ReceiptController;
use App\Http\Controllers\Api\V1\Stock\TransferController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\Storefront\MenuController;
use App\Http\Controllers\Api\V1\Storefront\OnlineStoreController;
use App\Http\Controllers\Api\V1\Storefront\PageController as StorePageController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    // ── jibiat-marchand.com : création de boutique, retrouver ses boutiques ──
    Route::middleware('zone:platform')->group(function () {
        Route::get('signup/slug', [SignupController::class, 'checkSlug'])->middleware('throttle:60,1')->name('signup.slug');
        Route::post('signup', [SignupController::class, 'start'])->middleware('throttle:verification-start')->name('signup');

        Route::post('account/login', [PlatformAccountController::class, 'login'])->middleware('throttle:login')->name('account.login');
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('account/stores', [PlatformAccountController::class, 'stores'])->name('account.stores');
            Route::post('account/stores/{store}/open', [PlatformAccountController::class, 'open'])->whereNumber('store')->name('account.open');
            Route::post('account/logout', [PlatformAccountController::class, 'logout'])->name('account.logout');
        });
    });

    // ── Code SMS et mot de passe oublié : plateforme et boutiques ──
    Route::middleware('zone:platform,store')->group(function () {
        Route::post('password/forgot', [PasswordResetController::class, 'start'])->middleware('throttle:verification-start')->name('password.forgot');
        Route::post('verifications/{token}/resend', [VerificationController::class, 'resend'])->middleware('throttle:verification-code')->name('verifications.resend');
        Route::post('verifications/{token}/confirm', [VerificationController::class, 'confirm'])->middleware('throttle:verification-code')->name('verifications.confirm');
    });

    // ── {slug}.jibiat-marchand.com : gestion de la boutique ──
    Route::middleware('zone:store')->group(function () {
        Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
        Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:login')->name('invitations.accept');

        Route::middleware(['auth:sanctum', 'member'])->group(function () {
            Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
            Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('auth/switch/{store}', [AuthController::class, 'switch'])->whereNumber('store')->name('auth.switch');

            Route::get('store', [StoreController::class, 'show'])->name('store.show');
            Route::middleware('permission:'.Permission::StoreManage->value)->group(function () {
                Route::patch('store', [StoreController::class, 'update'])->name('store.update');
                Route::put('store/slug', [StoreController::class, 'changeSlug'])->name('store.slug');
            });

            Route::middleware('permission:'.Permission::TeamManage->value)->group(function () {
                Route::get('team/roles', [MemberController::class, 'roles'])->name('team.roles');
                Route::get('team/members', [MemberController::class, 'index'])->name('team.members');
                Route::patch('team/members/{member}', [MemberController::class, 'update'])->whereNumber('member')->name('team.members.update');
                Route::delete('team/members/{member}', [MemberController::class, 'destroy'])->whereNumber('member')->name('team.members.destroy');
                Route::get('team/invitations', [InvitationController::class, 'index'])->name('team.invitations');
                Route::post('team/invitations', [InvitationController::class, 'store'])->name('team.invitations.store');
                Route::delete('team/invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation')->name('team.invitations.destroy');
            });

            Route::get('dashboard', DashboardController::class)->name('dashboard');
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
            Route::get('places', [DeliveryController::class, 'places'])->name('places.index');

            // ── Commandes ──
            $view = 'permission:'.Permission::OrdersView->value;
            $manage = 'permission:'.Permission::OrdersManage->value;
            $confirm = 'permission:'.Permission::OrdersConfirm->value;
            Route::middleware($view)->group(function () {
                Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
                Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
                Route::get('orders/{order}/invoice', [OrderController::class, 'invoice'])->whereNumber('order')->name('orders.invoice');
                Route::get('orders/{order}/label', [OrderController::class, 'label'])->whereNumber('order')->name('orders.label');
                Route::post('orders/{order}/notes', [OrderController::class, 'note'])->whereNumber('order')->name('orders.notes');
                Route::get('delivery-zones', [DeliveryController::class, 'zones'])->name('zones.index');
            });
            Route::middleware($manage)->group(function () {
                Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
                Route::post('orders/parse', [OrderController::class, 'parse'])->name('orders.parse');
                Route::post('orders/{order}/status', [OrderController::class, 'transition'])->whereNumber('order')->name('orders.status');
                Route::post('orders/{order}/payments', [OrderController::class, 'payment'])->whereNumber('order')->name('orders.payments');
                Route::post('places', [DeliveryController::class, 'addPlace'])->name('places.store');
                Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
                Route::put('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
                Route::post('customers/{customer}/blacklist', [CustomerController::class, 'blacklist'])->whereNumber('customer')->name('customers.blacklist');
                Route::delete('customers/{customer}/blacklist', [CustomerController::class, 'unblacklist'])->whereNumber('customer')->name('customers.unblacklist');
            });
            // Modification : gestionnaire, ou agent de confirmation tant que la commande est à confirmer (contrôle dans le contrôleur)
            Route::put('orders/{order}', [OrderController::class, 'update'])->whereNumber('order')
                ->middleware('permission:'.Permission::OrdersManage->value.'|'.Permission::OrdersConfirm->value)->name('orders.update');
            Route::middleware($confirm)->group(function () {
                Route::get('orders/confirmation-queue', [OrderController::class, 'queue'])->name('orders.queue');
                Route::post('orders/{order}/confirmations', [OrderController::class, 'confirmation'])->whereNumber('order')->name('orders.confirmations');
            });

            // ── Clients ──
            Route::middleware('permission:'.Permission::CustomersView->value)->group(function () {
                Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
                Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
            });

            // ── Transporteurs et expéditions ──
            Route::middleware('permission:'.Permission::CarriersManage->value)->group(function () {
                Route::get('carriers', [CarrierController::class, 'index'])->name('carriers.index');
                Route::post('carriers/delivery-app', [CarrierController::class, 'connectDeliveryApp'])->middleware('throttle:10,1')->name('carriers.delivery-app');
                Route::post('carriers/manual', [CarrierController::class, 'connectManual'])->name('carriers.manual');
                Route::patch('carriers/{carrier}', [CarrierController::class, 'update'])->whereNumber('carrier')->name('carriers.update');
                Route::delete('carriers/{carrier}', [CarrierController::class, 'destroy'])->whereNumber('carrier')->name('carriers.destroy');
                Route::get('carriers/{carrier}/zones', [CarrierController::class, 'zones'])->whereNumber('carrier')->name('carriers.zones');
                Route::put('carriers/{carrier}/zones', [CarrierController::class, 'updateZones'])->whereNumber('carrier')->name('carriers.zones.update');
                Route::post('carriers/{carrier}/sync', [CarrierController::class, 'sync'])->whereNumber('carrier')->middleware('throttle:10,1')->name('carriers.sync');
                Route::get('carriers/{carrier}/stock', [CarrierController::class, 'stock'])->whereNumber('carrier')->middleware('throttle:10,1')->name('carriers.stock');
                Route::post('carriers/{carrier}/hubs', [CarrierController::class, 'linkHub'])->whereNumber('carrier')->name('carriers.hubs');
                Route::get('carrier-payouts', [CarrierController::class, 'payouts'])->name('carriers.payouts');
            });
            Route::middleware('permission:'.Permission::ShipmentsManage->value)->group(function () {
                Route::get('carriers/active', [CarrierController::class, 'index'])->name('carriers.active');
                Route::get('shipments', [ShipmentController::class, 'index'])->name('shipments.index');
                Route::get('orders/{order}/shipping-quotes', [ShipmentController::class, 'quotes'])->whereNumber('order')->name('shipments.quotes');
                Route::post('orders/{order}/shipments', [ShipmentController::class, 'store'])->whereNumber('order')->name('shipments.store');
                Route::post('shipments/{shipment}/cancel', [ShipmentController::class, 'cancel'])->whereNumber('shipment')->name('shipments.cancel');
                Route::post('shipments/{shipment}/status', [ShipmentController::class, 'status'])->whereNumber('shipment')->name('shipments.status');
                Route::post('shipments/{shipment}/refresh', [ShipmentController::class, 'refresh'])->whereNumber('shipment')->middleware('throttle:30,1')->name('shipments.refresh');
                Route::get('shipments/{shipment}/message', [ShipmentController::class, 'message'])->whereNumber('shipment')->name('shipments.message');
            });

            // ── Boutique en ligne ──
            Route::middleware('permission:'.Permission::StoreManage->value)->group(function () {
                Route::get('online-store', [OnlineStoreController::class, 'show'])->name('online-store.show');
                Route::patch('online-store', [OnlineStoreController::class, 'update'])->name('online-store.update');
                Route::put('online-store/theme', [OnlineStoreController::class, 'updateTheme'])->name('online-store.theme');
                Route::post('online-store/theme/activate', [OnlineStoreController::class, 'activateTheme'])->name('online-store.theme.activate');
                Route::post('online-store/theme/hero-image', [OnlineStoreController::class, 'heroImage'])->name('online-store.hero.store');
                Route::delete('online-store/theme/hero-image', [OnlineStoreController::class, 'removeHeroImage'])->name('online-store.hero.destroy');
                Route::post('online-store/logo', [OnlineStoreController::class, 'logo'])->name('online-store.logo.store');
                Route::delete('online-store/logo', [OnlineStoreController::class, 'removeLogo'])->name('online-store.logo.destroy');
                Route::post('online-store/preview', [OnlineStoreController::class, 'preview'])->name('online-store.preview');
                Route::get('pages', [StorePageController::class, 'index'])->name('pages.index');
                Route::post('pages', [StorePageController::class, 'store'])->name('pages.store');
                Route::put('pages/{page}', [StorePageController::class, 'update'])->whereNumber('page')->name('pages.update');
                Route::delete('pages/{page}', [StorePageController::class, 'destroy'])->whereNumber('page')->name('pages.destroy');
                Route::get('menus', [MenuController::class, 'index'])->name('menus.index');
                Route::put('menus/{handle}', [MenuController::class, 'update'])->name('menus.update');
                Route::delete('menus/{handle}', [MenuController::class, 'destroy'])->name('menus.destroy');
            });

            // ── Zones de livraison ──
            Route::middleware('permission:'.Permission::StoreManage->value)->group(function () {
                Route::post('delivery-zones', [DeliveryController::class, 'storeZone'])->name('zones.store');
                Route::put('delivery-zones/{zone}', [DeliveryController::class, 'updateZone'])->whereNumber('zone')->name('zones.update');
                Route::delete('delivery-zones/{zone}', [DeliveryController::class, 'destroyZone'])->whereNumber('zone')->name('zones.destroy');
            });

            // ── Catalogue ──
            Route::middleware('permission:'.Permission::ProductsView->value)->group(function () {
                Route::get('products', [ProductController::class, 'index'])->name('products.index');
                Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('products.show');
                Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
                Route::get('collections/{collection}', [CollectionController::class, 'show'])->whereNumber('collection')->name('collections.show');
                Route::get('tags', [TagController::class, 'index'])->name('tags.index');
            });
            Route::middleware('permission:'.Permission::ProductsManage->value)->group(function () {
                Route::post('products', [ProductController::class, 'store'])->name('products.store');
                Route::put('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
                Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->whereNumber('product')->name('products.duplicate');
                Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('products.destroy');
                Route::post('products/{product}/images', [ProductImageController::class, 'store'])->whereNumber('product')->name('products.images.store');
                Route::put('products/{product}/images/order', [ProductImageController::class, 'reorder'])->whereNumber('product')->name('products.images.order');
                Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->whereNumber(['product', 'image'])->name('products.images.destroy');

                Route::post('collections', [CollectionController::class, 'store'])->name('collections.store');
                Route::put('collections/{collection}', [CollectionController::class, 'update'])->whereNumber('collection')->name('collections.update');
                Route::delete('collections/{collection}', [CollectionController::class, 'destroy'])->whereNumber('collection')->name('collections.destroy');

                Route::get('catalog/export', [CatalogTransferController::class, 'export'])->name('catalog.export');
                Route::post('catalog/import', [CatalogTransferController::class, 'import'])->middleware('throttle:20,1')->name('catalog.import');
                Route::post('catalog/import/delivery-app/preview', [CatalogTransferController::class, 'deliveryAppPreview'])->middleware('throttle:10,1')->name('catalog.delivery-app.preview');
                Route::post('catalog/import/delivery-app', [CatalogTransferController::class, 'deliveryAppImport'])->middleware('throttle:10,1')->name('catalog.delivery-app.import');
            });

            // ── Stock ──
            Route::middleware('permission:'.Permission::StockManage->value)->prefix('stock')->name('stock.')->group(function () {
                Route::get('summary', [LevelController::class, 'summary'])->name('summary');
                Route::get('levels', [LevelController::class, 'index'])->name('levels');
                Route::post('adjustments', [LevelController::class, 'adjust'])->name('adjust');
                Route::get('movements', [LevelController::class, 'movements'])->name('movements');
                Route::get('variants', [LevelController::class, 'variants'])->name('variants');

                Route::get('locations', [LocationController::class, 'index'])->name('locations.index');
                Route::post('locations', [LocationController::class, 'store'])->name('locations.store');
                Route::put('locations/{location}', [LocationController::class, 'update'])->whereNumber('location')->name('locations.update');

                Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
                Route::post('receipts', [ReceiptController::class, 'store'])->name('receipts.store');
                Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->whereNumber('receipt')->name('receipts.show');
                Route::get('suppliers', [ReceiptController::class, 'suppliers'])->name('suppliers');

                Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
                Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
                Route::get('transfers/{transfer}', [TransferController::class, 'show'])->whereNumber('transfer')->name('transfers.show');
                Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->whereNumber('transfer')->name('transfers.receive');
                Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->whereNumber('transfer')->name('transfers.cancel');

                Route::get('counts', [CountController::class, 'index'])->name('counts.index');
                Route::post('counts', [CountController::class, 'store'])->name('counts.store');
                Route::get('counts/{count}', [CountController::class, 'show'])->whereNumber('count')->name('counts.show');
                Route::put('counts/{count}/items', [CountController::class, 'saveItems'])->whereNumber('count')->name('counts.items');
                Route::post('counts/{count}/complete', [CountController::class, 'complete'])->whereNumber('count')->name('counts.complete');
                Route::post('counts/{count}/cancel', [CountController::class, 'cancel'])->whereNumber('count')->name('counts.cancel');
            });
        });
    });
});
