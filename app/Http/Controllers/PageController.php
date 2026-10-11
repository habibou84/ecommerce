<?php

namespace App\Http\Controllers;

use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy) {}

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
