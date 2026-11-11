@extends('layouts.page')

@section('title', 'Jibiat Marchand · Toi, tu fais ton live sur TikTok. Jibiat Marchand enregistre les commandes.')
@section('description', 'Tu fais ton live sur TikTok, tes clients envoient le code de l\'article sur WhatsApp : Jibiat Marchand répond, garde la pièce, demande le quartier et enregistre la commande. Application gratuite.')

@php
    $whatsapp = preg_replace('/\D+/', '', (string) config('platform.support_whatsapp'));
    $whatsappUrl = $whatsapp !== '' ? 'https://wa.me/'.$whatsapp.'?text='.rawurlencode('Bonjour, je souhaite en savoir plus sur Jibiat Marchand pour mes lives TikTok.') : null;
    // Lives payants (console) : quand ils sont allumés, on montre les lives offerts et les prix
    $liveBilling = app(\App\Services\Live\LiveBilling::class);
    $lives = $liveBilling->settings();
    // Palier gratuit mis à 0 dans la console : pas de ligne « Live de 0 à 0 commandes : Gratuit »
    $liveTiers = array_values(array_filter($liveBilling->tiers(), fn ($tier) => $tier['to'] === null || $tier['to'] >= max(1, $tier['from'])));
    $f = fn (int $amount) => number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}F";
    $tagline = 'Toi, tu fais ton live sur TikTok. Jibiat Marchand enregistre les commandes.';
    $faq = [
        ['Mon live se fait sur Jibiat Marchand ?', 'Non. Ton live se fait sur TikTok, comme d\'habitude, avec tes abonnés. Jibiat Marchand travaille à côté : il enregistre les commandes que tes clients envoient sur WhatsApp et les prépare pour la livraison.'],
        ['C\'est vraiment gratuit ?', $lives['paid']
            ? 'Oui : commandes, stock, livraisons et boutique en ligne sont gratuits. Pour les lives, tes '.$lives['free_orders'].' premières commandes en live sont offertes. Ensuite, tu ne paies que si tu vends : '.($lives['free_upto'] > 0 ? 'un live jusqu\'à '.$lives['free_upto'].' commandes est gratuit, au-delà ' : '').'le prix dépend du nombre de commandes (dès '.$f($lives['tier2_price']).'). Ou lives illimités : '.$f($lives['month_price']).' pour 30 jours.'
            : 'Oui : l\'application est gratuite, lives compris. Pas de carte bancaire à donner.'],
        ['Quel numéro WhatsApp utiliser ?', 'De préférence un numéro réservé à tes lives. Tu le relies dans l\'application en quelques minutes : c\'est sur ce numéro que tes clients envoient le code des articles.'],
        ['Faut-il un ordinateur ?', 'Non. Tout se fait depuis ton téléphone, en 5 minutes pour commencer.'],
        ['Mes clients paient comment ?', 'À la livraison, en espèces ou mobile money. Pour un client dans une autre ville, il paie avant l\'envoi avec ton lien Wave et tu valides son reçu en un geste.'],
        ['Qui livre mes colis ?', 'Tes propres livreurs, ou une entreprise de livraison Jibiat que tu connectes en un geste. À la fin du live, les commandes partent au livreur.'],
    ];
@endphp

@section('og')
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="Jibiat Marchand">
    <meta property="og:title" content="{{ $tagline }}">
    <meta property="og:description" content="Tes clients envoient le code de l'article sur WhatsApp : la pièce est gardée, le quartier demandé, la commande enregistrée. Gratuit.">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:image" content="{{ url('/videos/og-marchand.jpg') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'SoftwareApplication', 'name' => 'Jibiat Marchand', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, Android, iOS', 'url' => url('/'),
                    'description' => $tagline, 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'XOF']],
                ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endsection

@section('content')
    <header class="sticky top-0 z-40 border-b border-slate-100 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
            <a href="/" class="flex items-center gap-2 font-semibold">
                <img src="/brand/logo-256.png" alt="" class="h-9 w-9" width="36" height="36">
                <span>Jibiat <span class="text-emerald-700">Marchand</span></span>
            </a>
            <nav class="hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex" aria-label="Navigation principale">
                <a href="#qui-fait-quoi" class="hover:text-slate-900">Comment ça marche</a>
                <a href="#prix" class="hover:text-slate-900">Prix</a>
                <a href="#questions" class="hover:text-slate-900">Questions</a>
                <a href="/bien-demarrer" class="hover:text-slate-900">Bien démarrer</a>
            </nav>
            <div class="flex items-center gap-2 text-sm">
                <a href="{{ $loginUrl }}" class="btn-secondary">Se connecter</a>
                <a href="{{ $signupUrl }}" class="btn-brand hidden sm:inline-flex">Créer mon espace marchand</a>
            </div>
        </div>
    </header>

    <main>
        {{-- La phrase clé et la vidéo problème → solution --}}
        <section class="overflow-hidden bg-gradient-to-br from-orange-900 via-orange-700 to-orange-500 text-white">
            <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-12 sm:py-16 lg:grid-cols-2">
                <div class="text-center lg:text-left">
                    <h1 class="text-4xl font-black leading-tight tracking-tight text-balance sm:text-5xl">
                        Toi, tu fais ton live sur TikTok.<br><span class="text-amber-200">Jibiat Marchand enregistre les commandes.</span>
                    </h1>
                    <p class="mt-5 text-lg text-orange-50">
                        Tes clients envoient le code de l'article sur ton WhatsApp. Jibiat Marchand leur répond, garde la pièce, demande le quartier
                        et enregistre la commande. Toi, tu restes concentré sur ton live.
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row lg:justify-start sm:justify-center">
                        <a href="{{ $signupUrl }}" class="btn-brand bg-white px-6 py-3 text-base font-extrabold text-orange-800 hover:bg-orange-50">Créer mon espace marchand gratuit</a>
                        @if ($whatsappUrl)
                            <a href="{{ $whatsappUrl }}" class="btn-secondary border-white/40 bg-transparent px-6 py-3 text-base text-white hover:bg-white/10" target="_blank" rel="noopener">Poser une question sur WhatsApp</a>
                        @endif
                    </div>
                    <p class="mt-3 text-sm font-semibold text-orange-100">Gratuit · 5 minutes · Depuis ton téléphone</p>
                </div>
                <div class="flex justify-center">
                    <video class="w-full max-w-[19rem] rounded-[2rem] shadow-2xl ring-1 ring-white/20" poster="/videos/live/probleme-solution-v.jpg?v=1" width="1080" height="1920" controls playsinline preload="none" aria-label="Vidéo : ton live sur TikTok, les commandes enregistrées par Jibiat Marchand">
                        <source src="/videos/live/probleme-solution-v.mp4?v=1" type="video/mp4">
                    </video>
                </div>
            </div>
        </section>

        {{-- Qui fait quoi : le live reste sur TikTok --}}
        <section id="qui-fait-quoi" class="scroll-mt-20 py-14 sm:py-20">
            <div class="mx-auto max-w-3xl px-4">
                <p class="text-center font-bold text-emerald-700">Qui fait quoi</p>
                <h2 class="mt-1 text-center text-3xl font-black tracking-tight text-balance sm:text-4xl">3 applications, chacune son rôle</h2>
                <ol class="mx-auto mt-8 max-w-xl space-y-3">
                    <li class="flex items-center gap-4 rounded-2xl bg-slate-100 p-4">
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-slate-900 text-2xl text-white" aria-hidden="true">♪</span>
                        <span><b class="block text-lg">TikTok : ton live</b><span class="text-slate-600">Tu montres l'article et tu dis son code : A1, A2…</span></span>
                    </li>
                    <li class="flex items-center gap-4 rounded-2xl bg-green-50 p-4">
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-[#25d366] text-2xl text-white" aria-hidden="true">✆</span>
                        <span><b class="block text-lg">WhatsApp : la commande du client</b><span class="text-slate-600">Il envoie « A1 M » sur ton numéro</span></span>
                    </li>
                    <li class="flex items-center gap-4 rounded-2xl bg-emerald-50 p-4 ring-2 ring-emerald-500">
                        <img src="/brand/logo-256.png" alt="" class="h-12 w-12 shrink-0 rounded-xl bg-white" width="48" height="48">
                        <span><b class="block text-lg">Jibiat Marchand : enregistre la commande</b><span class="text-slate-600">Répond, garde la pièce, demande le quartier, enregistre la commande, prépare la livraison</span></span>
                    </li>
                </ol>
                <p class="mx-auto mt-4 max-w-xl rounded-xl bg-orange-50 p-3 text-center font-bold text-orange-800">Jibiat Marchand ne remplace pas TikTok : ton live reste sur TikTok, avec tes abonnés.</p>
            </div>
        </section>

        {{-- Les 5 étapes et la vidéo « Comment ça marche » --}}
        <section id="etapes" class="scroll-mt-20 bg-slate-950 py-14 text-white sm:py-20">
            <div class="mx-auto grid max-w-5xl items-center gap-10 px-4 lg:grid-cols-2">
                <div>
                    <p class="font-bold text-emerald-300">Comment ça marche</p>
                    <h2 class="mt-1 text-3xl font-black tracking-tight text-balance sm:text-4xl">Avant ton live TikTok : 4 réglages. Puis c'est parti.</h2>
                    <ol class="mt-6 space-y-4 text-lg">
                        @foreach ([
                            ['Connecte ton WhatsApp', 'Le numéro où tes clients commandent'],
                            ['Définis tes prix de livraison et d\'expédition', null],
                            ['Choisis tes articles', 'Chacun reçoit son code : A1, A2…'],
                            ['Télécharge la carte de tes articles', 'À mettre en fond vert sur ton live TikTok'],
                            ['Lance ton live sur TikTok, puis ouvre les commandes du live dans Jibiat Marchand', null],
                        ] as $i => [$step, $hint])
                            <li class="flex gap-3">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full {{ $i === 4 ? 'bg-white text-slate-900' : 'bg-orange-600' }} font-black">{{ $i + 1 }}</span>
                                <span class="font-semibold">{{ $step }}@if ($hint)<small class="block text-base font-normal text-slate-300">{{ $hint }}</small>@endif</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
                <div class="flex justify-center">
                    <video class="w-full max-w-[17rem] rounded-[2rem] shadow-2xl ring-1 ring-white/15" poster="/videos/live/comment-ca-marche-v.jpg?v=4" width="1080" height="1920" controls playsinline preload="none" aria-label="Vidéo : comment ça marche, en 5 étapes">
                        <source src="/videos/live/comment-ca-marche-v.mp4?v=4" type="video/mp4">
                    </video>
                </div>
            </div>
        </section>

        {{-- Et tous les jours --}}
        <section class="py-14 sm:py-20">
            <div class="mx-auto max-w-5xl px-4">
                <p class="text-center font-bold text-emerald-700">Et tous les jours</p>
                <h2 class="mt-1 text-center text-3xl font-black tracking-tight sm:text-4xl">Toute ta boutique au même endroit</h2>
                <div class="mt-8 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach ([
                        ['💬', 'Commandes', 'WhatsApp, Facebook, TikTok : le message du client devient une commande'],
                        ['🛵', 'Livraison', 'Tes livreurs ou des entreprises Jibiat, en un geste'],
                        ['🛍️', 'Boutique en ligne', 'Ouverte jour et nuit, à ton nom'],
                        ['💰', 'Argent', 'Paiements Wave, caisse et bénéfice du soir'],
                    ] as [$icon, $title, $text])
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <span class="text-3xl" aria-hidden="true">{{ $icon }}</span>
                            <b class="mt-2 block text-lg">{{ $title }}</b>
                            <p class="mt-1 text-slate-600">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Prix --}}
        <section id="prix" class="scroll-mt-20 bg-slate-50 py-14 sm:py-20">
            <div class="mx-auto max-w-xl px-4">
                <p class="text-center font-bold text-emerald-700">Prix</p>
                <h2 class="mt-1 text-center text-3xl font-black tracking-tight sm:text-4xl">L'application est gratuite</h2>
                <div class="mt-8 divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white text-lg ring-1 ring-slate-200">
                    <p class="flex justify-between gap-4 p-4"><span>Commandes, stock, livraisons, boutique en ligne</span><b class="shrink-0 text-emerald-700">Gratuit</b></p>
                    @if ($lives['paid'])
                        <p class="flex justify-between gap-4 p-4"><span>Tes {{ $lives['free_orders'] }} premières commandes en live</span><b class="shrink-0 text-emerald-700">Offertes</b></p>
                        @foreach ($liveTiers as $tier)
                            <p class="flex justify-between gap-4 p-4"><span>Live de {{ $tier['to'] === null ? 'plus de '.($tier['from'] - 1) : $tier['from'].' à '.$tier['to'] }} commandes</span><b class="shrink-0 {{ $tier['price'] ? '' : 'text-emerald-700' }}">{{ $tier['price'] ? $f($tier['price']) : 'Gratuit' }}</b></p>
                        @endforeach
                        <p class="flex justify-between gap-4 p-4"><span>Lives illimités 30 jours</span><b class="shrink-0">{{ $f($lives['month_price']) }}</b></p>
                    @else
                        <p class="flex justify-between gap-4 p-4"><span>Commandes de tes lives TikTok</span><b class="shrink-0 text-emerald-700">Gratuit</b></p>
                    @endif
                </div>
                <p class="mt-3 text-center text-slate-600">Sans carte bancaire.</p>
                <div class="mt-8 flex justify-center">
                    <video class="w-full max-w-[17rem] rounded-[2rem] shadow-2xl ring-1 ring-slate-200" poster="/videos/live/tout-gratuit-v.jpg?v=1" width="720" height="1280" controls playsinline preload="none" aria-label="Vidéo : tout est gratuit">
                        <source src="/videos/live/tout-gratuit-v.mp4?v=1" type="video/mp4">
                    </video>
                </div>
            </div>
        </section>

        {{-- Questions --}}
        <section id="questions" class="scroll-mt-20 py-14 sm:py-20">
            <div class="mx-auto max-w-3xl px-4">
                <h2 class="text-center text-3xl font-black tracking-tight sm:text-4xl">Tes questions</h2>
                <div class="mt-8 divide-y divide-slate-200 rounded-2xl ring-1 ring-slate-200">
                    @foreach ($faq as [$q, $a])
                        <details class="group p-5">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-lg font-semibold">{{ $q }}<span class="text-xl text-emerald-700 transition group-open:rotate-45" aria-hidden="true">+</span></summary>
                            <p class="mt-3 text-slate-600">{{ $a }}</p>
                        </details>
                    @endforeach
                </div>
                <p class="mt-6 text-center"><a href="/bien-demarrer" class="font-semibold text-emerald-700 hover:underline">▶ Bien démarrer : les étapes essentielles en courtes vidéos</a></p>
            </div>
        </section>

        {{-- Appel final --}}
        <section class="bg-emerald-800 py-14 text-center text-white">
            <div class="mx-auto max-w-3xl px-4">
                <h2 class="text-3xl font-black tracking-tight text-balance sm:text-4xl">Ton prochain live TikTok, sans rien noter</h2>
                <a href="{{ $signupUrl }}" class="btn-brand mt-8 bg-white px-8 py-3 text-base font-extrabold text-emerald-900 hover:bg-emerald-50">Créer mon espace marchand gratuit</a>
                <p class="mt-4 text-sm text-emerald-100">Tu es une entreprise de livraison ? <a href="https://jibiat.com" class="font-semibold underline">Découvre Jibiat</a> et reçois les colis des boutiques.</p>
            </div>
        </section>
    </main>

    <footer class="mx-auto max-w-6xl px-4 py-8 text-sm text-slate-500">
        © {{ date('Y') }} Jibiat Marchand · <a href="/cgu" class="hover:underline">Conditions d'utilisation et de vente</a> · <a href="/confidentialite" class="hover:underline">Confidentialité</a> · <a href="https://jibiat.com" class="hover:underline">Jibiat, pour les entreprises de livraison</a>
    </footer>

    @if ($whatsappUrl)
        <a href="{{ $whatsappUrl }}" class="fixed bottom-5 right-5 z-50 grid h-14 w-14 place-items-center rounded-full bg-[#25d366] text-white shadow-xl ring-4 ring-white/70" target="_blank" rel="noopener" aria-label="Nous écrire sur WhatsApp">
            <svg viewBox="0 0 24 24" class="h-7 w-7" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2l-.4-.3Z"/></svg>
        </a>
    @endif
@endsection
