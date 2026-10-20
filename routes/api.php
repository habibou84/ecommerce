<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\Catalog\BundleController;
use App\Http\Controllers\Api\V1\Catalog\CatalogTransferController;
use App\Http\Controllers\Api\V1\Catalog\CollectionController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ProductImageController;
use App\Http\Controllers\Api\V1\Catalog\TagController;
use App\Http\Controllers\Api\V1\Console\ConsoleAuthController;
use App\Http\Controllers\Api\V1\Console\ConsoleController;
use App\Http\Controllers\Api\V1\Couriers\CashController;
use App\Http\Controllers\Api\V1\Couriers\CourierAppController;
use App\Http\Controllers\Api\V1\Couriers\CourierController;
use App\Http\Controllers\Api\V1\Couriers\PayrollController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\Finance\CashOverviewController;
use App\Http\Controllers\Api\V1\Finance\CashRegisterController;
use App\Http\Controllers\Api\V1\Finance\RecurringExpenseController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\Marketing\CampaignController;
use App\Http\Controllers\Api\V1\Marketing\LoyaltyController;
use App\Http\Controllers\Api\V1\Marketing\PricingController;
use App\Http\Controllers\Api\V1\Marketing\PromotionController;
use App\Http\Controllers\Api\V1\Marketing\ReviewController;
use App\Http\Controllers\Api\V1\Marketing\ShareController;
use App\Http\Controllers\Api\V1\Marketing\SocialController;
use App\Http\Controllers\Api\V1\Marketing\TrackingController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\Messaging\ConversationController;
use App\Http\Controllers\Api\V1\Messaging\WhatsAppController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Orders\AlertSettingsController;
use App\Http\Controllers\Api\V1\Orders\CustomerController;
use App\Http\Controllers\Api\V1\Orders\DeliveryController;
use App\Http\Controllers\Api\V1\Orders\DeliveryOptionsController;
use App\Http\Controllers\Api\V1\Orders\MessageTextController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Orders\ReturnController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\Payments\OrderPaymentController;
use App\Http\Controllers\Api\V1\Payments\PaymentSettingsController;
use App\Http\Controllers\Api\V1\PlatformAccountController;
use App\Http\Controllers\Api\V1\Reports\StatsController;
use App\Http\Controllers\Api\V1\SetupGuideController;
use App\Http\Controllers\Api\V1\Shipping\CarrierBalanceController;
use App\Http\Controllers\Api\V1\Shipping\CarrierController;
use App\Http\Controllers\Api\V1\Shipping\ShipmentController;
use App\Http\Controllers\Api\V1\Shipping\ShippingRuleController;
use App\Http\Controllers\Api\V1\SignupController;
use App\Http\Controllers\Api\V1\Stock\CountController;
use App\Http\Controllers\Api\V1\Stock\LevelController;
use App\Http\Controllers\Api\V1\Stock\LocationController;
use App\Http\Controllers\Api\V1\Stock\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Stock\ReceiptController;
use App\Http\Controllers\Api\V1\Stock\StoreTransferController;
use App\Http\Controllers\Api\V1\Stock\TransferController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\StoreDataController;
use App\Http\Controllers\Api\V1\Storefront\MenuController;
use App\Http\Controllers\Api\V1\Storefront\OnlineStoreController;
use App\Http\Controllers\Api\V1\Storefront\PageController as StorePageController;
use App\Http\Controllers\Api\V1\StoreGroupController;
use App\Http\Controllers\Api\V1\SupportSessionController;
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

    // ── admin.jibiat-marchand.com : console du super administrateur ──
    Route::middleware('zone:console')->prefix('console')->name('console.')->group(function () {
        Route::post('auth/login', [ConsoleAuthController::class, 'login'])->middleware('throttle:login')->name('login');
        Route::post('auth/verify', [ConsoleAuthController::class, 'verify'])->middleware('throttle:verification-code')->name('verify');

        Route::middleware(['auth:sanctum', 'super_admin'])->group(function () {
            Route::get('auth/me', [ConsoleAuthController::class, 'me'])->name('me');
            Route::post('auth/logout', [ConsoleAuthController::class, 'logout'])->name('logout');
            Route::get('stats', [ConsoleController::class, 'stats'])->name('stats');
            Route::get('stores', [ConsoleController::class, 'stores'])->name('stores');
            Route::get('stores/{store}', [ConsoleController::class, 'store'])->whereNumber('store')->name('stores.show');
            Route::post('stores/{store}/suspend', [ConsoleController::class, 'suspend'])->whereNumber('store')->name('stores.suspend');
            Route::post('stores/{store}/reactivate', [ConsoleController::class, 'reactivate'])->whereNumber('store')->name('stores.reactivate');
            Route::post('stores/{store}/trial', [ConsoleController::class, 'extendTrial'])->whereNumber('store')->name('stores.trial');
            Route::post('stores/{store}/offer', [ConsoleController::class, 'offer'])->whereNumber('store')->name('stores.offer');
            Route::post('stores/{store}/support', [ConsoleController::class, 'support'])->whereNumber('store')->name('stores.support');
            Route::post('support-sessions/{session}/end', [ConsoleController::class, 'endSupport'])->whereNumber('session')->name('support.end');
            Route::get('plans', [ConsoleController::class, 'plans'])->name('plans');
            Route::put('plans/{plan}', [ConsoleController::class, 'updatePlan'])->whereNumber('plan')->name('plans.update');
            Route::get('invoices', [ConsoleController::class, 'invoices'])->name('invoices');
            Route::get('ai-usage', [ConsoleController::class, 'aiUsage'])->name('ai-usage');
        });
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
            Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
            Route::delete('devices', [DeviceController::class, 'destroy'])->name('devices.destroy');
            Route::put('auth/account', [AuthController::class, 'updateAccount'])->name('auth.account');
            Route::put('auth/password', [AuthController::class, 'updatePassword'])->middleware('throttle:login')->name('auth.password');

            Route::get('store', [StoreController::class, 'show'])->name('store.show');
            Route::middleware('permission:'.Permission::StoreManage->value)->group(function () {
                Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
                Route::post('domains', [DomainController::class, 'store'])->middleware('throttle:10,1')->name('domains.store');
                Route::post('domains/{domain}/verify', [DomainController::class, 'verify'])->whereNumber('domain')->middleware('throttle:20,1')->name('domains.verify');
                Route::post('domains/{domain}/primary', [DomainController::class, 'primary'])->whereNumber('domain')->name('domains.primary');
                Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->whereNumber('domain')->name('domains.destroy');
                Route::patch('store', [StoreController::class, 'update'])->name('store.update');
                Route::put('store/slug', [StoreController::class, 'changeSlug'])->name('store.slug');
            });

            Route::middleware('permission:'.Permission::TeamManage->value)->group(function () {
                Route::get('team/roles', [MemberController::class, 'roles'])->name('team.roles');
                Route::get('team/members', [MemberController::class, 'index'])->name('team.members');
                Route::get('team/stores', [MemberController::class, 'stores'])->name('team.stores');
                Route::put('team/members/{member}/stores', [MemberController::class, 'updateStores'])->whereNumber('member')->name('team.members.stores');
                Route::patch('team/members/{member}', [MemberController::class, 'update'])->whereNumber('member')->name('team.members.update');
                Route::delete('team/members/{member}', [MemberController::class, 'destroy'])->whereNumber('member')->name('team.members.destroy');
                Route::get('team/invitations', [InvitationController::class, 'index'])->name('team.invitations');
                Route::post('team/invitations', [InvitationController::class, 'store'])->name('team.invitations.store');
                Route::delete('team/invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation')->name('team.invitations.destroy');
            });

            Route::get('dashboard', DashboardController::class)->name('dashboard');
            // Guide de démarrage pas à pas (étapes selon le rôle de chacun)
            Route::get('setup-guide', [SetupGuideController::class, 'show'])->name('setup-guide.show');
            Route::put('setup-guide/{step}', [SetupGuideController::class, 'skip'])->where('step', '[a-z_]+')->name('setup-guide.skip');
            // La boutique met fin à un accès du support de la plateforme
            Route::post('support-sessions/{session}/end', SupportSessionController::class)->whereNumber('session')
                ->middleware('permission:'.Permission::TeamManage->value)->name('support.end');

            // ── Boutiques du propriétaire ──
            Route::prefix('my-stores')->name('my-stores.')->group(function () {
                Route::get('/', [StoreGroupController::class, 'index'])->name('index');
                Route::get('stats', [StoreGroupController::class, 'stats'])->name('stats');
                Route::get('cash', [StoreGroupController::class, 'cash'])->name('cash');
                Route::get('couriers', [StoreGroupController::class, 'couriers'])->name('couriers');
                Route::post('couriers/{courier}/stores/{store}', [StoreGroupController::class, 'shareCourier'])->whereNumber(['courier', 'store'])->name('couriers.share');
                Route::delete('couriers/{courier}/stores/{store}', [StoreGroupController::class, 'unshareCourier'])->whereNumber(['courier', 'store'])->name('couriers.unshare');
                Route::post('couriers/{courier}/remittances', [StoreGroupController::class, 'remitCourier'])->whereNumber('courier')->name('couriers.remit');
                Route::post('couriers/{courier}/payouts', [StoreGroupController::class, 'payCourier'])->whereNumber('courier')->name('couriers.pay');
                Route::post('/', [StoreGroupController::class, 'store'])->middleware('throttle:10,1')->name('store');
                Route::post('{store}/attach', [StoreGroupController::class, 'attach'])->whereNumber('store')->name('attach');
                Route::delete('{store}/attach', [StoreGroupController::class, 'detach'])->whereNumber('store')->name('detach');
            });

            // ── Abonnement de la boutique ──
            Route::middleware('permission:'.Permission::BillingManage->value)->prefix('billing')->name('billing.')->group(function () {
                Route::get('/', [BillingController::class, 'show'])->name('show');
                Route::post('checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('checkout');
                Route::post('invoices/{number}/check', [BillingController::class, 'check'])->where('number', 'JM-\\d{4}-\\d{6}')->name('check');
                Route::get('invoices/{invoice}/receipt', [BillingController::class, 'receipt'])->whereNumber('invoice')->name('receipt');
                Route::post('cancel', [BillingController::class, 'cancel'])->name('cancel');
            });
            Route::get('stats', [StatsController::class, 'show'])->middleware('permission:'.Permission::FinanceView->value)->name('stats');
            Route::post('stats/ask', [StatsController::class, 'ask'])->middleware(['permission:'.Permission::FinanceView->value, 'throttle:30,1'])->name('stats.ask');
            Route::get('cash-overview', [CashOverviewController::class, 'show'])->middleware('permission:'.Permission::FinanceView->value)->name('cash.overview');
            Route::get('cash-register', [CashRegisterController::class, 'show'])->middleware('permission:'.Permission::CashManage->value.'|'.Permission::FinanceView->value)->name('cash-register.show');
            Route::get('recurring-expenses', [RecurringExpenseController::class, 'index'])->middleware('permission:'.Permission::CashManage->value.'|'.Permission::FinanceView->value)->name('recurring-expenses.index');
            Route::middleware('permission:'.Permission::CashManage->value)->group(function () {
                Route::post('cash-register/open', [CashRegisterController::class, 'open'])->name('cash-register.open');
                Route::post('cash-register/close', [CashRegisterController::class, 'close'])->name('cash-register.close');
                Route::post('cash-register/movements', [CashRegisterController::class, 'movement'])->name('cash-register.movements');
                Route::post('recurring-expenses', [RecurringExpenseController::class, 'store'])->name('recurring-expenses.store');
                Route::put('recurring-expenses/{expense}', [RecurringExpenseController::class, 'update'])->whereNumber('expense')->name('recurring-expenses.update');
                Route::delete('recurring-expenses/{expense}', [RecurringExpenseController::class, 'destroy'])->whereNumber('expense')->name('recurring-expenses.destroy');
                Route::post('counter-sales', [CashRegisterController::class, 'sell'])->middleware('throttle:60,1')->name('counter-sales.store');
                Route::post('counter-sales/{order}/whatsapp', [CashRegisterController::class, 'receiptWhatsapp'])->whereNumber('order')->name('counter-sales.whatsapp');
            });
            Route::get('reports/schedule', [StatsController::class, 'schedule'])->middleware('permission:'.Permission::OrdersView->value)->name('reports.schedule');
            Route::put('reports/schedule', [StatsController::class, 'updateSchedule'])->middleware('permission:'.Permission::OrdersView->value)->name('reports.schedule.update');
            // ── Données de la boutique : export complet et fermeture (propriétaire, contrôle dans le contrôleur) ──
            Route::get('store-data/exports', [StoreDataController::class, 'index'])->name('store-data.exports');
            Route::post('store-data/exports', [StoreDataController::class, 'export'])->middleware('throttle:5,1')->name('store-data.export');
            Route::get('store-data/exports/{export}/download', [StoreDataController::class, 'download'])->whereNumber('export')->name('store-data.download');
            Route::post('store-data/close', [StoreDataController::class, 'close'])->middleware('throttle:5,1')->name('store-data.close');
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
                Route::get('orders/labels', [OrderController::class, 'labels'])->name('orders.labels');
                Route::get('orders/export', [OrderController::class, 'export'])->name('orders.export');
                Route::post('orders/{order}/notes', [OrderController::class, 'note'])->whereNumber('order')->name('orders.notes');
                Route::get('delivery-zones', [DeliveryController::class, 'zones'])->name('zones.index');
            });
            Route::middleware($manage)->group(function () {
                Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
                Route::get('orders/{order}/returns', [ReturnController::class, 'forOrder'])->whereNumber('order')->name('orders.returns.index');
                Route::post('orders/{order}/returns', [ReturnController::class, 'store'])->whereNumber('order')->name('orders.returns.store');
                Route::post('returns/{return}/receive', [ReturnController::class, 'receive'])->whereNumber('return')->name('returns.receive');
                Route::post('returns/{return}/cancel', [ReturnController::class, 'cancel'])->whereNumber('return')->name('returns.cancel');
                Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
                // Liste : plusieurs commandes à la fois (livreur, statut, WhatsApp)
                Route::post('orders/bulk', [OrderController::class, 'bulk'])->name('orders.bulk');
                Route::post('orders/parse', [OrderController::class, 'parse'])->name('orders.parse');
                Route::post('orders/draft', [OrderController::class, 'draft'])->middleware('throttle:30,1')->name('orders.draft');
                // Saisie : qui peut livrer ce quartier et à quel prix, zones et tarifs d'une entreprise de livraison
                Route::post('orders/delivery-options', DeliveryOptionsController::class)->name('orders.delivery-options');
                Route::get('orders/delivery-options/places', [DeliveryOptionsController::class, 'places'])->name('orders.delivery-options.places');
                Route::get('orders/delivery-options/carriers/{carrier}', [DeliveryOptionsController::class, 'tariffs'])->whereNumber('carrier')->name('orders.delivery-options.tariffs');
                Route::post('orders/{order}/status', [OrderController::class, 'transition'])->whereNumber('order')->name('orders.status');
                Route::post('orders/{order}/payments', [OrderController::class, 'payment'])->whereNumber('order')->name('orders.payments');
                Route::post('places', [DeliveryController::class, 'addPlace'])->name('places.store');
                Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
                Route::put('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
                Route::post('customers/{customer}/loyalty', [LoyaltyController::class, 'adjust'])->whereNumber('customer')->name('customers.loyalty');
                Route::post('customers/{customer}/blacklist', [CustomerController::class, 'blacklist'])->whereNumber('customer')->name('customers.blacklist');
                Route::delete('customers/{customer}/blacklist', [CustomerController::class, 'unblacklist'])->whereNumber('customer')->name('customers.unblacklist');
            });
            // Modification : gestionnaire, ou agent de confirmation tant que la commande est à confirmer (contrôle dans le contrôleur)
            Route::put('orders/{order}', [OrderController::class, 'update'])->whereNumber('order')
                ->middleware('permission:'.Permission::OrdersManage->value.'|'.Permission::OrdersConfirm->value)->name('orders.update');
            Route::middleware($confirm)->group(function () {
                Route::get('orders/confirmation-queue', [OrderController::class, 'queue'])->name('orders.queue');
                Route::post('orders/{order}/confirmations', [OrderController::class, 'confirmation'])->whereNumber('order')->name('orders.confirmations');
                // Demande de confirmation par WhatsApp (boutons Confirmer / Annuler), depuis le numéro de la boutique
                Route::post('orders/{order}/whatsapp-confirmation', [OrderController::class, 'whatsappConfirmation'])->whereNumber('order')->name('orders.whatsapp-confirmation');
            });

            // ── Clients ──
            // Boîte « Messages » : l'équipe qui saisit ou confirme les commandes
            Route::middleware('permission:'.Permission::OrdersManage->value.'|'.Permission::OrdersConfirm->value)->group(function () {
                Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
                Route::get('conversations/unread', [ConversationController::class, 'unread'])->name('conversations.unread');
                Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation')->name('conversations.show');
                Route::post('conversations/{conversation}/messages', [ConversationController::class, 'reply'])->whereNumber('conversation')->name('conversations.reply');
                Route::post('conversations/{conversation}/suggest', [ConversationController::class, 'suggest'])->whereNumber('conversation')->middleware('throttle:30,1')->name('conversations.suggest');
            });
            Route::middleware('permission:'.Permission::CustomersView->value)->group(function () {
                Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
                Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
                Route::get('customers/elsewhere', [CustomerController::class, 'elsewhere'])->name('customers.elsewhere');
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
                // Grille de tarifs : zones créées par le marchand (entreprise sans API), prix par zone
                Route::post('carriers/{carrier}/tariff-zones', [CarrierController::class, 'storeZone'])->whereNumber('carrier')->name('carriers.tariff-zones.store');
                Route::patch('carrier-zones/{zone}', [CarrierController::class, 'updateZone'])->whereNumber('zone')->name('carrier-zones.update');
                Route::delete('carrier-zones/{zone}', [CarrierController::class, 'destroyZone'])->whereNumber('zone')->name('carrier-zones.destroy');
                Route::post('carriers/{carrier}/sync', [CarrierController::class, 'sync'])->whereNumber('carrier')->middleware('throttle:10,1')->name('carriers.sync');
                Route::get('carriers/{carrier}/stock', [CarrierController::class, 'stock'])->whereNumber('carrier')->middleware('throttle:10,1')->name('carriers.stock');
                Route::post('carriers/{carrier}/hubs', [CarrierController::class, 'linkHub'])->whereNumber('carrier')->name('carriers.hubs');
                Route::get('carrier-payouts', [CarrierController::class, 'payouts'])->name('carriers.payouts');
                Route::get('carrier-balances', [CarrierBalanceController::class, 'index'])->name('carriers.balances');
                Route::get('carrier-balances/{carrier}', [CarrierBalanceController::class, 'show'])->whereNumber('carrier')->name('carriers.balances.show');
                Route::get('carrier-balances/{carrier}/export', [CarrierBalanceController::class, 'export'])->whereNumber('carrier')->name('carriers.balances.export');
                Route::post('carriers/{carrier}/payouts', [CarrierBalanceController::class, 'storePayout'])->whereNumber('carrier')->name('carriers.payouts.store');
            });
            Route::middleware('permission:'.Permission::ShipmentsManage->value)->group(function () {
                Route::get('carriers/active', [CarrierController::class, 'index'])->name('carriers.active');
                Route::get('shipments', [ShipmentController::class, 'index'])->name('shipments.index');
                Route::get('orders/{order}/shipping-quotes', [ShipmentController::class, 'quotes'])->whereNumber('order')->name('shipments.quotes');
                Route::post('orders/{order}/shipments', [ShipmentController::class, 'store'])->whereNumber('order')->name('shipments.store');
                Route::post('shipments/{shipment}/cancel', [ShipmentController::class, 'cancel'])->whereNumber('shipment')->name('shipments.cancel');
                // Actions sur la course chez l'entreprise de livraison (Delivery App)
                Route::patch('shipments/{shipment}/carrier', [ShipmentController::class, 'updateAtCarrier'])->whereNumber('shipment')->middleware('throttle:30,1')->name('shipments.carrier-update');
                Route::post('shipments/{shipment}/decision', [ShipmentController::class, 'decide'])->whereNumber('shipment')->middleware('throttle:30,1')->name('shipments.decision');
                Route::post('shipments/{shipment}/return-request', [ShipmentController::class, 'requestReturn'])->whereNumber('shipment')->middleware('throttle:30,1')->name('shipments.return-request');
                Route::post('shipments/{shipment}/notes', [ShipmentController::class, 'note'])->whereNumber('shipment')->middleware('throttle:30,1')->name('shipments.notes');
                Route::post('shipments/{shipment}/status', [ShipmentController::class, 'status'])->whereNumber('shipment')->name('shipments.status');
                Route::post('shipments/{shipment}/refresh', [ShipmentController::class, 'refresh'])->whereNumber('shipment')->middleware('throttle:30,1')->name('shipments.refresh');
                Route::get('shipments/{shipment}/message', [ShipmentController::class, 'message'])->whereNumber('shipment')->name('shipments.message');
                Route::post('shipments/{shipment}/assign', [ShipmentController::class, 'assign'])->whereNumber('shipment')->name('shipments.assign');
                Route::get('shipments/{shipment}/proof', [ShipmentController::class, 'proof'])->whereNumber('shipment')->name('shipments.proof');
                Route::get('shipping-rules', [ShippingRuleController::class, 'index'])->name('shipping-rules.index');
                Route::post('shipping-rules', [ShippingRuleController::class, 'store'])->name('shipping-rules.store');
                Route::put('shipping-rules/{rule}', [ShippingRuleController::class, 'update'])->whereNumber('rule')->name('shipping-rules.update');
                Route::delete('shipping-rules/{rule}', [ShippingRuleController::class, 'destroy'])->whereNumber('rule')->name('shipping-rules.destroy');
                Route::patch('shipping-rules', [ShippingRuleController::class, 'settings'])->name('shipping-rules.settings');
            });

            // ── Paiements en ligne ──
            Route::middleware('permission:'.Permission::PaymentsManage->value)->prefix('payments')->name('payments.')->group(function () {
                Route::get('settings', [PaymentSettingsController::class, 'show'])->name('settings');
                Route::patch('settings', [PaymentSettingsController::class, 'update'])->name('settings.update');
                Route::post('accounts', [PaymentSettingsController::class, 'storeAccount'])->name('accounts.store');
                Route::patch('accounts/{account}', [PaymentSettingsController::class, 'updateAccount'])->whereNumber('account')->name('accounts.update');
                Route::delete('accounts/{account}', [PaymentSettingsController::class, 'destroyAccount'])->whereNumber('account')->name('accounts.destroy');
                Route::put('routes', [PaymentSettingsController::class, 'routes'])->name('routes');
                Route::get('simulator', [PaymentSettingsController::class, 'simulator'])->name('simulator');
                Route::get('report', [PaymentSettingsController::class, 'report'])->name('report');
            });
            Route::post('orders/{order}/payment-links', [OrderPaymentController::class, 'link'])->whereNumber('order')
                ->middleware('permission:'.Permission::OrdersManage->value)->name('orders.payment-links');
            Route::post('orders/{order}/refunds', [OrderPaymentController::class, 'refund'])->whereNumber('order')
                ->middleware('permission:'.Permission::PaymentsManage->value)->name('orders.refunds');

            // ── Promotions : codes promo, ventes flash, livraison offerte, prix par quantité ──
            Route::middleware('permission:'.Permission::StoreManage->value)->group(function () {
                Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
                Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
                Route::put('promotions/{discount}', [PromotionController::class, 'update'])->whereNumber('discount')->name('promotions.update');
                Route::delete('promotions/{discount}', [PromotionController::class, 'destroy'])->whereNumber('discount')->name('promotions.destroy');
                Route::patch('promotions/settings', [PromotionController::class, 'settings'])->name('promotions.settings');
                Route::get('marketing/tracking', [TrackingController::class, 'show'])->name('tracking.show');
                Route::put('marketing/tracking', [TrackingController::class, 'update'])->name('tracking.update');
                Route::post('marketing/catalog-feed/renew', [TrackingController::class, 'renewFeed'])->name('catalog-feed.renew');
                Route::get('message-texts', [MessageTextController::class, 'index'])->name('message-texts.index');
                Route::put('message-texts', [MessageTextController::class, 'update'])->name('message-texts.update');
                Route::post('message-texts/preview', [MessageTextController::class, 'preview'])->name('message-texts.preview');
                Route::get('loyalty', [LoyaltyController::class, 'show'])->name('loyalty.show');
                Route::put('loyalty', [LoyaltyController::class, 'update'])->name('loyalty.update');
                Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
                Route::post('campaigns/preview', [CampaignController::class, 'preview'])->name('campaigns.preview');
                Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
                Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign')->name('campaigns.update');
                Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send'])->whereNumber('campaign')->middleware('throttle:10,1')->name('campaigns.send');
                Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->whereNumber('campaign')->name('campaigns.destroy');
                Route::get('social', [SocialController::class, 'index'])->name('social.index');
                Route::post('social/connect/{provider}', [SocialController::class, 'connect'])->whereIn('provider', ['meta', 'tiktok'])->name('social.connect');
                Route::get('social/pages/{key}', [SocialController::class, 'pages'])->name('social.pages');
                Route::post('social/pages/{key}', [SocialController::class, 'choosePage'])->name('social.pages.choose');
                Route::delete('social/accounts/{network}', [SocialController::class, 'disconnect'])->whereIn('network', ['facebook', 'instagram', 'tiktok'])->name('social.disconnect');
                Route::post('products/{product}/social/captions', [SocialController::class, 'captions'])->whereNumber('product')->middleware('throttle:20,1')->name('social.captions');
                Route::post('products/{product}/social/posts', [SocialController::class, 'publish'])->whereNumber('product')->middleware('throttle:20,1')->name('social.publish');
                Route::post('social/posts/{post}/retry', [SocialController::class, 'retry'])->whereNumber('post')->middleware('throttle:10,1')->name('social.retry');
                Route::delete('social/posts/{post}', [SocialController::class, 'cancel'])->whereNumber('post')->name('social.cancel');
                Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
                Route::post('reviews/{review}/publish', [ReviewController::class, 'publish'])->whereNumber('review')->name('reviews.publish');
                Route::post('reviews/{review}/hide', [ReviewController::class, 'hide'])->whereNumber('review')->name('reviews.hide');
                Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->whereNumber('review')->name('reviews.destroy');
            });
            Route::get('products/{product}/volume-prices', [PricingController::class, 'volumePrices'])->whereNumber('product')
                ->middleware('permission:'.Permission::ProductsView->value)->name('products.volume-prices');
            Route::put('products/{product}/volume-prices', [PricingController::class, 'updateVolumePrices'])->whereNumber('product')
                ->middleware('permission:'.Permission::ProductsManage->value)->name('products.volume-prices.update');
            Route::post('products/{product}/share', [ShareController::class, 'product'])->whereNumber('product')
                ->middleware('permission:'.Permission::ProductsView->value)->name('products.share');
            Route::post('orders/pricing', [PricingController::class, 'quote'])
                ->middleware('permission:'.Permission::OrdersManage->value.'|'.Permission::OrdersConfirm->value)->name('orders.pricing');

            // ── WhatsApp de la boutique et paniers abandonnés ──
            Route::middleware('permission:'.Permission::StoreManage->value)->group(function () {
                Route::get('whatsapp', [WhatsAppController::class, 'show'])->name('whatsapp.show');
                Route::put('whatsapp', [WhatsAppController::class, 'update'])->name('whatsapp.update');
                Route::post('whatsapp/embedded', [WhatsAppController::class, 'connectEmbedded'])->middleware('throttle:login')->name('whatsapp.embedded');
                Route::delete('whatsapp', [WhatsAppController::class, 'destroy'])->name('whatsapp.destroy');
            });
            Route::middleware('permission:'.Permission::OrdersManage->value)->group(function () {
                Route::get('carts/abandoned', [WhatsAppController::class, 'abandonedCarts'])->name('carts.abandoned');
                Route::post('carts/{cart}/recovery', [WhatsAppController::class, 'markRecovered'])->whereNumber('cart')->name('carts.recovery');
            });

            // ── Livreurs de la boutique ──
            Route::middleware('permission:'.Permission::CouriersManage->value)->group(function () {
                Route::get('couriers', [CourierController::class, 'index'])->name('couriers.index');
                Route::post('couriers', [CourierController::class, 'store'])->name('couriers.store');
                Route::patch('couriers/settings', [CourierController::class, 'settings'])->name('couriers.settings');
                Route::patch('couriers/{courier}', [CourierController::class, 'update'])->whereNumber('courier')->name('couriers.update');
                Route::post('couriers/{courier}/invitation', [CourierController::class, 'reinvite'])->whereNumber('courier')->name('couriers.reinvite');
                Route::post('couriers/{courier}/deactivate', [CourierController::class, 'deactivate'])->whereNumber('courier')->name('couriers.deactivate');
                Route::post('courier-pay-rules', [CourierController::class, 'storeRule'])->name('couriers.rules.store');
                Route::put('courier-pay-rules/{rule}', [CourierController::class, 'updateRule'])->whereNumber('rule')->name('couriers.rules.update');
                Route::delete('courier-pay-rules/{rule}', [CourierController::class, 'destroyRule'])->whereNumber('rule')->name('couriers.rules.destroy');
                Route::get('couriers/{courier}/earnings', [PayrollController::class, 'earnings'])->whereNumber('courier')->name('couriers.earnings');
                Route::post('couriers/{courier}/earnings', [PayrollController::class, 'adjust'])->whereNumber('courier')->name('couriers.earnings.adjust');
                Route::post('couriers/{courier}/payouts', [PayrollController::class, 'createPayout'])->whereNumber('courier')->name('couriers.payouts.store');
                Route::post('courier-payouts/{payout}/pay', [PayrollController::class, 'pay'])->whereNumber('payout')->name('couriers.payouts.pay');
                Route::post('courier-payouts/{payout}/cancel', [PayrollController::class, 'cancel'])->whereNumber('payout')->name('couriers.payouts.cancel');
            });
            // Caisse : gérant ou caissier
            Route::middleware('permission:'.Permission::CouriersManage->value.'|'.Permission::FinanceView->value)->group(function () {
                Route::get('cash', [CashController::class, 'index'])->name('cash.index');
                Route::get('cash/couriers/{courier}', [CashController::class, 'collections'])->whereNumber('courier')->name('cash.collections');
                Route::post('cash/couriers/{courier}/remittances', [CashController::class, 'remit'])->whereNumber('courier')->name('cash.remit');
                Route::post('cash/declarations/{declaration}/confirm', [CashController::class, 'confirmDeclaration'])->whereNumber('declaration')->name('cash.declarations.confirm');
                Route::post('cash/declarations/{declaration}/reject', [CashController::class, 'rejectDeclaration'])->whereNumber('declaration')->name('cash.declarations.reject');
                Route::get('cash/declarations/{declaration}/proof', [CashController::class, 'declarationProof'])->whereNumber('declaration')->name('cash.declarations.proof');
            });

            // ── Application livreur (/livreur) ──
            Route::middleware('courier')->prefix('courier')->name('courier.')->group(function () {
                Route::get('me', [CourierAppController::class, 'me'])->name('me');
                Route::patch('availability', [CourierAppController::class, 'availability'])->name('availability');
                Route::get('missions', [CourierAppController::class, 'missions'])->name('missions');
                Route::get('missions/{shipment}', [CourierAppController::class, 'show'])->whereNumber('shipment')->name('missions.show');
                Route::post('missions/{shipment}/door-payment', [CourierAppController::class, 'doorPayment'])->whereNumber('shipment')->middleware('throttle:20,1')->name('missions.door-payment');
                Route::post('missions/{shipment}/accept', [CourierAppController::class, 'accept'])->whereNumber('shipment')->name('missions.accept');
                Route::post('missions/{shipment}/decline', [CourierAppController::class, 'decline'])->whereNumber('shipment')->middleware('throttle:20,1')->name('missions.decline');
                Route::post('missions/{shipment}/steps', [CourierAppController::class, 'step'])->whereNumber('shipment')->middleware('throttle:60,1')->name('missions.step');
                Route::put('route', [CourierAppController::class, 'route'])->name('route');
                Route::post('location', [CourierAppController::class, 'location'])->middleware('throttle:30,1')->name('location');
                Route::get('cash', [CourierAppController::class, 'cash'])->name('cash');
                Route::post('remittances', [CourierAppController::class, 'declareRemittance'])->middleware('throttle:10,1')->name('remittances.declare');
                Route::get('earnings', [CourierAppController::class, 'earnings'])->name('earnings');
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
                Route::get('delivery-calendar', [DeliveryController::class, 'calendar'])->name('delivery-calendar.show');
                Route::put('delivery-calendar', [DeliveryController::class, 'updateCalendar'])->name('delivery-calendar.update');
                Route::get('alert-settings', [AlertSettingsController::class, 'show'])->name('alert-settings.show');
                Route::put('alert-settings', [AlertSettingsController::class, 'update'])->name('alert-settings.update');
                Route::post('delivery-zones', [DeliveryController::class, 'storeZone'])->name('zones.store');
                Route::put('delivery-zones/{zone}', [DeliveryController::class, 'updateZone'])->whereNumber('zone')->name('zones.update');
                Route::delete('delivery-zones/{zone}', [DeliveryController::class, 'destroyZone'])->whereNumber('zone')->name('zones.destroy');
            });

            // ── Catalogue ──
            Route::middleware('permission:'.Permission::ProductsView->value)->group(function () {
                Route::get('products', [ProductController::class, 'index'])->name('products.index');
                Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('products.show');
                Route::get('products/{product}/stores', [ProductController::class, 'stores'])->whereNumber('product')->name('products.stores');
                Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
                Route::get('collections/{collection}', [CollectionController::class, 'show'])->whereNumber('collection')->name('collections.show');
                Route::get('tags', [TagController::class, 'index'])->name('tags.index');
            });
            Route::middleware('permission:'.Permission::ProductsManage->value)->group(function () {
                Route::post('products', [ProductController::class, 'store'])->name('products.store');
                Route::post('products/draft', [ProductController::class, 'draft'])->middleware('throttle:20,1')->name('products.draft');
                Route::put('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
                Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->whereNumber('product')->name('products.duplicate');
                Route::post('products/{product}/copy', [ProductController::class, 'copy'])->whereNumber('product')->name('products.copy');
                Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('products.destroy');
                Route::get('products/{product}/bundle', [BundleController::class, 'show'])->whereNumber('product')->name('products.bundle.show');
                Route::put('products/{product}/bundle', [BundleController::class, 'update'])->whereNumber('product')->name('products.bundle.update');
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
                Route::get('suppliers', [PurchaseOrderController::class, 'suppliers'])->name('suppliers');
                Route::post('suppliers', [PurchaseOrderController::class, 'storeSupplier'])->name('suppliers.store');
                Route::put('suppliers/{supplier}', [PurchaseOrderController::class, 'updateSupplier'])->whereNumber('supplier')->name('suppliers.update');
                Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
                Route::get('purchase-orders/suggestions', [PurchaseOrderController::class, 'suggestions'])->name('purchase-orders.suggestions');
                Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
                Route::get('purchase-orders/{order}', [PurchaseOrderController::class, 'show'])->whereNumber('order')->name('purchase-orders.show');
                Route::put('purchase-orders/{order}', [PurchaseOrderController::class, 'update'])->whereNumber('order')->name('purchase-orders.update');
                Route::post('purchase-orders/{order}/send', [PurchaseOrderController::class, 'send'])->whereNumber('order')->name('purchase-orders.send');
                Route::post('purchase-orders/{order}/receive', [PurchaseOrderController::class, 'receive'])->whereNumber('order')->name('purchase-orders.receive');
                Route::post('purchase-orders/{order}/pay', [PurchaseOrderController::class, 'pay'])->whereNumber('order')->name('purchase-orders.pay');
                Route::post('purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->whereNumber('order')->name('purchase-orders.cancel');

                Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
                Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
                Route::get('transfers/{transfer}', [TransferController::class, 'show'])->whereNumber('transfer')->name('transfers.show');
                Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->whereNumber('transfer')->name('transfers.receive');
                Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->whereNumber('transfer')->name('transfers.cancel');

                Route::get('store-transfers', [StoreTransferController::class, 'index'])->name('store-transfers.index');
                Route::post('store-transfers', [StoreTransferController::class, 'store'])->name('store-transfers.store');

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
