<?php

namespace App\Http\Controllers;

use App\Services\Platform\Features;
use App\Support\StartSteps;
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

    /** Vidéo envoyée par lien (WhatsApp) : page qui la lit tout de suite, au lieu d'un fichier .mp4 à télécharger */
    public function video(string $name): View
    {
        abort_unless(is_file(public_path("videos/{$name}.mp4")), 404);
        $titles = ['live/comment-ca-marche-v' => 'Vendre en live : comment ça marche', 'live/probleme-solution-v' => 'Toi, tu fais ton live. Jibiat Marchand enregistre les commandes.', 'live/tout-gratuit-v' => 'Commandes, stock, livraisons, boutique en ligne, caisse : tout est gratuit'];
        $step = collect(StartSteps::all())->first(fn (array $s) => strtok($s['video'], '?') === "/videos/{$name}.mp4");

        return view('platform.video', [
            'title' => $titles[$name] ?? ($step['title'] ?? 'Vidéo Jibiat Marchand'),
            'src' => "/videos/{$name}.mp4", 'poster' => "/videos/{$name}.jpg",
        ]);
    }
}
