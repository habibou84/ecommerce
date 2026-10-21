@extends('layouts.page')

@section('title', 'Jibiat Marchand · Votre boutique en ligne, vos commandes, vos livraisons')
@section('description', 'Créez votre boutique en ligne, recevez vos commandes WhatsApp, Facebook et TikTok, et livrez avec des entreprises de livraison fiables, connectées en un clic. Essai gratuit.')

@php
    $whatsapp = preg_replace('/\D+/', '', (string) config('platform.support_whatsapp'));
    $whatsappUrl = $whatsapp !== '' ? 'https://wa.me/'.$whatsapp.'?text='.rawurlencode('Bonjour, je souhaite en savoir plus sur Jibiat Marchand pour ma boutique.') : null;
    $faq = [
        ['Combien ça coûte ?', 'Vous commencez gratuitement : '.config('platform.trial_days').' jours d\'essai de toutes les fonctions, sans carte bancaire. Ensuite, l\'offre Gratuit reste disponible, et les offres payantes commencent à 5 000 F par mois.'],
        ['Comment je trouve une entreprise de livraison ?', 'Dans Livraisons › Entreprises de livraison, l\'annuaire Jibiat montre les entreprises près de chez vous avec leur réussite et leur délai réels des 90 derniers jours. Vous touchez « Connecter » et c\'est fait.'],
        ['Et si j\'ai déjà mes propres livreurs ?', 'Gardez-les : ils ont leur application, avec leurs missions, les encaissements et les preuves de livraison. Vous pouvez mélanger vos livreurs et des entreprises de livraison.'],
        ['Je vends seulement sur WhatsApp et Facebook. C\'est pour moi ?', 'Oui. Collez le message du client : la commande est prête. Vous avez aussi une boutique en ligne à partager, sans rien installer.'],
        ['Comment je suis payé ?', 'À la livraison en espèces, ou en ligne avec Wave, Orange Money, MTN, Moov ou par carte. Les reversements des entreprises de livraison sont rapprochés de vos commandes automatiquement.'],
        ['Faut-il un ordinateur ?', 'Non. Tout se fait depuis votre téléphone, en 5 minutes pour commencer.'],
    ];
@endphp

@section('og')
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="Jibiat Marchand">
    <meta property="og:title" content="Jibiat Marchand · Vendez partout, livrez sans souci">
    <meta property="og:description" content="Boutique en ligne, commandes WhatsApp, Facebook et TikTok, stock, et des entreprises de livraison fiables connectées en un clic.">
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
                    'description' => 'Application des e-commerçants : boutique en ligne, commandes, stock, livraisons et paiements.',
                    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'XOF']],
                ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endsection

@section('content')
    <header class="sticky top-0 z-40 border-b border-slate-100 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
            <a href="/" class="flex items-center gap-2 font-semibold">
                <span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-700 font-bold text-white">J</span>
                <span>Jibiat <span class="text-emerald-700">Marchand</span></span>
            </a>
            <nav class="hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex" aria-label="Navigation principale">
                <a href="#livraison" class="hover:text-slate-900">Livraison</a>
                <a href="#fonctions" class="hover:text-slate-900">Fonctions</a>
                <a href="#tarifs" class="hover:text-slate-900">Tarifs</a>
                <a href="#questions" class="hover:text-slate-900">Questions</a>
            </nav>
            <div class="flex items-center gap-2 text-sm">
                <a href="{{ $loginUrl }}" class="btn-secondary">Se connecter</a>
                <a href="{{ $signupUrl }}" class="btn-brand">Créer ma boutique</a>
            </div>
        </div>
    </header>

    <main>
        {{-- Présentation et vidéo --}}
        <section class="overflow-hidden bg-gradient-to-b from-emerald-950 via-emerald-900 to-emerald-800 text-white">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-14 sm:py-20 lg:grid-cols-2">
                <div>
                    <p class="inline-block rounded-full bg-white/10 px-3 py-1 text-sm font-medium text-emerald-100 ring-1 ring-white/15">Pour les e-commerçants de Côte d'Ivoire</p>
                    <h1 class="mt-5 text-4xl font-bold leading-tight tracking-tight text-balance sm:text-5xl">
                        Vendez partout. Livrez avec des pros. <span class="text-emerald-300">Encaissez sans courir.</span>
                    </h1>
                    <p class="mt-5 text-lg text-emerald-50/90">
                        Votre boutique en ligne, vos commandes WhatsApp, Facebook et TikTok, votre stock et vos livraisons dans une seule application,
                        depuis votre téléphone. Et des entreprises de livraison fiables, connectées en un clic.
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ $signupUrl }}" class="btn-brand bg-white px-6 py-3 text-base text-emerald-900 hover:bg-emerald-50">Créer ma boutique gratuitement</a>
                        @if ($whatsappUrl)
                            <a href="{{ $whatsappUrl }}" class="btn-secondary border-white/30 bg-transparent px-6 py-3 text-base text-white hover:bg-white/10" target="_blank" rel="noopener">Poser une question sur WhatsApp</a>
                        @endif
                    </div>
                    <p class="mt-3 text-sm text-emerald-100/80">5 minutes pour commencer · {{ config('platform.trial_days') }} jours d'essai de toutes les fonctions · Sans carte bancaire</p>
                </div>
                <div class="flex justify-center">
                    <video class="w-full max-w-[20rem] rounded-[2rem] shadow-2xl ring-1 ring-white/15" poster="/videos/marchand-boutique-v.jpg" width="1080" height="1920" autoplay muted loop playsinline preload="metadata" aria-label="Vidéo : votre boutique en ligne et vos commandes dans Jibiat Marchand">
                        <source src="/videos/marchand-boutique-v.mp4" type="video/mp4">
                    </video>
                </div>
            </div>
        </section>

        {{-- L'atout : la livraison --}}
        <section id="livraison" class="scroll-mt-20 py-16 sm:py-20">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 lg:grid-cols-2">
                <div class="order-2 flex justify-center lg:order-1">
                    <video class="w-full max-w-[18rem] rounded-[2rem] shadow-xl ring-1 ring-slate-200" poster="/videos/marchand-livreur-fiable-v.jpg" width="1080" height="1920" controls playsinline preload="none" aria-label="Vidéo : trouver une entreprise de livraison fiable et s'y connecter en un clic">
                        <source src="/videos/marchand-livreur-fiable-v.mp4" type="video/mp4">
                    </video>
                </div>
                <div class="order-1 lg:order-2">
                    <p class="font-semibold text-emerald-700">La livraison, enfin simple</p>
                    <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Des livreurs fiables, toujours disponibles, en un clic</h2>
                    <p class="mt-4 text-lg text-slate-600">
                        Fini le livreur qui ne répond plus. Choisissez parmi les entreprises de livraison qui travaillent avec Jibiat,
                        d'après leurs vrais résultats, et confiez-leur vos colis sans quitter votre boutique.
                    </p>
                    <ul class="mt-6 space-y-4">
                        @foreach ([
                            ['🔎', 'Un annuaire avec les vrais chiffres', 'Réussite et délai moyen des 90 derniers jours, calculés par l\'application, pas déclarés.'],
                            ['🔗', 'Connecter en un clic', 'Vous vous connectez chez l\'entreprise ou ouvrez votre compte, et c\'est fait. Aucune clé à copier.'],
                            ['📍', 'Chaque colis suivi en direct', 'Ramassé, en route, livré, échec : le statut arrive tout seul dans votre commande, avec la photo de preuve.'],
                            ['💰', 'Votre argent contrôlé', 'Ce que chaque entreprise vous doit, les retards de reversement, le rapprochement automatique.'],
                            ['⚖️', 'Le meilleur choix pour chaque commande', 'Jibiat Marchand compare vos livreurs et les entreprises : prix, réussite et délai, zone par zone.'],
                        ] as [$icon, $title, $text])
                            <li class="flex gap-4">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-emerald-50 text-xl" aria-hidden="true">{{ $icon }}</span>
                                <span><span class="block font-semibold">{{ $title }}</span><span class="block text-slate-600">{{ $text }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                    <a href="https://jibiat.com/annuaire" class="mt-6 inline-block font-semibold text-emerald-700 hover:underline">Voir l'annuaire des entreprises de livraison →</a>
                </div>
            </div>
        </section>

        {{-- Toutes les fonctions --}}
        <section id="fonctions" class="scroll-mt-20 bg-slate-50 py-16 sm:py-20">
            <div class="mx-auto max-w-6xl px-4">
                <div class="mx-auto max-w-2xl text-center">
                    <p class="font-semibold text-emerald-700">Tout au même endroit</p>
                    <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Tout ce qu'il faut pour vendre plus, avec moins d'efforts</h2>
                </div>
                <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        ['🛍️', 'Votre boutique en ligne', 'Sur votre-nom.jibiat-marchand.com : catalogue, panier, commande et suivi pour vos clients.'],
                        ['💬', 'Les commandes de tous vos canaux', 'WhatsApp, Facebook, TikTok, téléphone : collez le message du client, la commande est prête.'],
                        ['📸', 'Une fiche produit depuis une photo', 'Prenez la photo, l\'intelligence artificielle propose le titre et la description.'],
                        ['✅', 'Confirmation avant envoi', 'Une file « à confirmer » pour appeler ou relancer vos clients et réduire les refus à la porte.'],
                        ['📦', 'Un stock qui se tient tout seul', 'Chaque vente, retour ou réception le met à jour, variante par variante.'],
                        ['📥', 'Tous vos messages réunis', 'WhatsApp, Messenger et Instagram dans une seule boîte, avec une réponse proposée.'],
                        ['🛵', 'Vos propres livreurs', 'Leur application marche même sans réseau : missions, encaissements, preuves de livraison.'],
                        ['📱', 'Paiement mobile money', 'Wave, Orange Money, MTN, Moov ou carte, en ligne ou par QR code à la porte.'],
                        ['📊', 'Vos chiffres chaque soir', 'Ventes, marge, meilleurs produits et canaux, avec le rapport du jour par WhatsApp.'],
                    ] as [$icon, $title, $text])
                        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                            <span class="text-2xl" aria-hidden="true">{{ $icon }}</span>
                            <h3 class="mt-3 font-semibold">{{ $title }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Comment ça marche --}}
        <section class="py-16 sm:py-20">
            <div class="mx-auto max-w-6xl px-4">
                <h2 class="text-center text-3xl font-bold tracking-tight sm:text-4xl">Prêt en 5 minutes</h2>
                <ol class="mt-10 grid gap-6 md:grid-cols-3">
                    @foreach ([
                        ['Créez votre boutique', 'Votre nom, votre adresse en ligne et votre téléphone. C\'est tout.'],
                        ['Ajoutez vos produits', 'Une photo suffit pour commencer. Partagez le lien de votre boutique.'],
                        ['Connectez votre livraison', 'Vos livreurs, ou une entreprise de l\'annuaire en un clic.'],
                    ] as $i => [$title, $text])
                        <li class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                            <span class="grid h-10 w-10 place-items-center rounded-full bg-emerald-700 font-bold text-white">{{ $i + 1 }}</span>
                            <h3 class="mt-4 text-lg font-semibold">{{ $title }}</h3>
                            <p class="mt-1 text-slate-600">{{ $text }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- Tarifs (offres de la console) --}}
        @if ($plans->isNotEmpty())
            <section id="tarifs" class="scroll-mt-20 bg-slate-50 py-16 sm:py-20">
                <div class="mx-auto max-w-6xl px-4">
                    <div class="mx-auto max-w-2xl text-center">
                        <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">Des prix simples, en francs CFA</h2>
                        <p class="mt-3 text-lg text-slate-600">{{ config('platform.trial_days') }} jours d'essai de toutes les fonctions, puis l'offre de votre choix. Sans engagement.</p>
                    </div>
                    <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($plans as $plan)
                            @php
                                $limits = $plan->limits ?? [];
                                $count = fn ($key, $one, $many) => array_key_exists($key, $limits) && $limits[$key] !== null ? ($limits[$key] === 0 ? null : $limits[$key].' '.($limits[$key] > 1 ? $many : $one)) : ucfirst($many).' illimités';
                            @endphp
                            <div @class(['flex flex-col rounded-2xl bg-white p-6 shadow-sm ring-1', 'ring-2 ring-emerald-600' => $plan->code === 'pro', 'ring-slate-200' => $plan->code !== 'pro'])>
                                <h3 class="font-semibold">{{ $plan->name }}</h3>
                                <p class="mt-2"><span class="text-3xl font-bold">{{ number_format($plan->monthly_price, 0, ',', ' ') }} F</span><span class="text-slate-500"> / mois</span></p>
                                <ul class="mt-5 space-y-2 text-sm text-slate-700">
                                    <li>✓ {{ ($limits['orders_per_month'] ?? null) !== null ? $limits['orders_per_month'].' commandes par mois' : 'Commandes illimitées' }}</li>
                                    <li>✓ {{ ($limits['products'] ?? null) !== null ? $limits['products'].' produits' : 'Produits illimités' }}</li>
                                    <li>{{ $count('carrier_connections', 'entreprise de livraison', 'entreprises de livraison') ? '✓ '.$count('carrier_connections', 'entreprise de livraison connectée', 'entreprises de livraison connectées') : '— Vos livreurs seulement' }}</li>
                                    <li>✓ {{ $count('couriers', 'livreur', 'livreurs') }}</li>
                                    <li>{{ ! empty($plan->features['online_payment']) ? '✓ Paiement en ligne' : '— Paiement à la livraison' }}</li>
                                </ul>
                                <div class="mt-auto pt-6"><a href="{{ $signupUrl }}" class="btn-brand w-full">Commencer</a></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- Questions --}}
        <section id="questions" class="scroll-mt-20 py-16 sm:py-20">
            <div class="mx-auto max-w-3xl px-4">
                <h2 class="text-center text-3xl font-bold tracking-tight sm:text-4xl">Vos questions</h2>
                <div class="mt-10 divide-y divide-slate-200 rounded-2xl ring-1 ring-slate-200">
                    @foreach ($faq as [$q, $a])
                        <details class="group p-5">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold">{{ $q }}<span class="text-xl text-emerald-700 transition group-open:rotate-45" aria-hidden="true">+</span></summary>
                            <p class="mt-3 text-slate-600">{{ $a }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Appel final --}}
        <section class="bg-emerald-800 py-16 text-center text-white">
            <div class="mx-auto max-w-3xl px-4">
                <h2 class="text-3xl font-bold tracking-tight text-balance sm:text-4xl">Votre boutique en ligne en 5 minutes, vos livraisons en un clic</h2>
                <a href="{{ $signupUrl }}" class="btn-brand mt-8 bg-white px-8 py-3 text-base text-emerald-900 hover:bg-emerald-50">Créer ma boutique gratuitement</a>
                <p class="mt-4 text-sm text-emerald-100">Vous êtes une entreprise de livraison ? <a href="https://jibiat.com" class="font-semibold underline">Découvrez Jibiat</a> et recevez les colis des boutiques.</p>
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
