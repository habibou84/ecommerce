<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\InventoryCount;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\StockTransfer;
use App\Services\Places\Places;
use App\Support\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Jibiat\Socle\PhoneNumber;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Une boutique par requête (réinitialisée entre deux requêtes d'un même processus)
        $this->app->scoped(Tenancy::class);
        // Lieux visibles d'une boutique : mis en cache le temps de la requête
        $this->app->scoped(Places::class);
    }

    public function boot(): void
    {
        // Montant dans les gabarits : @money($order->total) → « 12 500 F »
        Blade::directive('money', fn (string $expression) => "<?php echo e(\\Jibiat\\Socle\\Money::format((int) ({$expression}))); ?>");

        // Noms courts dans les colonnes polymorphes (étiquettes, références du journal de stock)
        Relation::morphMap([
            'product' => Product::class,
            'stock_receipt' => StockReceipt::class,
            'stock_transfer' => StockTransfer::class,
            'inventory_count' => InventoryCount::class,
            'order' => Order::class,
            'customer' => Customer::class,
        ]);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login-phone:'.PhoneNumber::normalize((string) $request->input('phone'))),
        ]);

        // Ouverture d'une vérification par SMS (création de boutique, mot de passe oublié)
        RateLimiter::for('verification-start', fn (Request $request) => Limit::perHour(10)->by('verification-start:'.$request->ip()));

        // Boutique en ligne : appels JSON (panier, quartier, frais) et passage de commande
        RateLimiter::for('storefront', fn (Request $request) => Limit::perMinute(120)->by('storefront:'.$request->ip()));
        RateLimiter::for('storefront-order', fn (Request $request) => [
            Limit::perMinute(5)->by('storefront-order:'.$request->ip()),
            Limit::perHour(30)->by('storefront-order-hour:'.$request->ip()),
        ]);

        RateLimiter::for('verification-code', fn (Request $request) => Limit::perMinute(10)->by('verification-code:'.$request->ip()));
    }
}
