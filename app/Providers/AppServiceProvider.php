<?php

namespace App\Providers;

use App\Support\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
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
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login-phone:'.PhoneNumber::normalize((string) $request->input('phone'))),
        ]);

        // Ouverture d'une vérification par SMS (création de boutique, mot de passe oublié)
        RateLimiter::for('verification-start', fn (Request $request) => Limit::perHour(10)->by('verification-start:'.$request->ip()));

        RateLimiter::for('verification-code', fn (Request $request) => Limit::perMinute(10)->by('verification-code:'.$request->ip()));
    }
}
