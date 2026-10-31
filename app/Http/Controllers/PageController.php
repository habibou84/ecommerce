<?php

namespace App\Http\Controllers;

use App\Services\Platform\Features;
use App\Support\Tenancy;
use App\Support\TrialOffer;
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
            'trialDays' => TrialOffer::days(),
            'trialOffer' => TrialOffer::duration().' de l\'offre '.TrialOffer::planName(),
            'supportWhatsapp' => config('platform.support_whatsapp'),
            'store' => $store ? ['id' => $store->id, 'name' => $store->name, 'slug' => $store->slug] : null,
            // Inscription de nouvelles boutiques fermée dans la console
            'signupOpen' => app(Features::class)->enabled('signup'),
        ]]);
    }
}
