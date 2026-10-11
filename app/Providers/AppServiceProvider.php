<?php

namespace App\Providers;

use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\StockTransfer;
use App\Support\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Jibiat\Socle\PhoneNumber;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Une boutique par requête (réinitialisée entre deux requêtes d'un même processus)
        $this->app->scoped(Tenancy::class);
    }

    public function boot(): void
    {
        // Noms courts dans les colonnes polymorphes (étiquettes, références du journal de stock)
        Relation::morphMap([
            'product' => Product::class,
            'stock_receipt' => StockReceipt::class,
            'stock_transfer' => StockTransfer::class,
            'inventory_count' => InventoryCount::class,
        ]);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login-phone:'.PhoneNumber::normalize((string) $request->input('phone'))),
        ]);

        // Ouverture d'une vérification par SMS (création de boutique, mot de passe oublié)
        RateLimiter::for('verification-start', fn (Request $request) => Limit::perHour(10)->by('verification-start:'.$request->ip()));

        RateLimiter::for('verification-code', fn (Request $request) => Limit::perMinute(10)->by('verification-code:'.$request->ip()));
    }
}
