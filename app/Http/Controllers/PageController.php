<?php

namespace App\Http\Controllers;

use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Racine de chaque adresse : présentation (plateforme), site de la boutique, console.
     */
    public function home(): View|JsonResponse
    {
        return match ($this->tenancy->zone()) {
            Tenancy::PLATFORM => view('platform.home', ['signupUrl' => '/creer-ma-boutique', 'loginUrl' => '/connexion']),
            Tenancy::STORE, Tenancy::CUSTOM_DOMAIN => view('storefront.coming-soon', ['store' => $this->tenancy->store()]),
            Tenancy::CONSOLE => view('console.home'),
            default => response()->json(['message' => 'API Jibiat Marchand.'], 404),
        };
    }

    /**
     * Application Vue : création de boutique et connexion (plateforme), gestion et invitation (boutique).
     */
    public function spa(Request $request): View
    {
        $store = $this->tenancy->store();

        return view('app', ['boot' => [
            'zone' => $this->tenancy->zone(),
            'domain' => Tenancy::domain(),
            'platformUrl' => Tenancy::platformUrl(),
            'storeUrlTemplate' => Tenancy::storeUrl('{slug}'),
            'trialDays' => (int) config('platform.trial_days'),
            'store' => $store ? ['id' => $store->id, 'name' => $store->name, 'slug' => $store->slug] : null,
        ]]);
    }
}
