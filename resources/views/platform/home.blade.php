@extends('layouts.page')

@section('title', 'Jibiat Marchand · Votre boutique en ligne, vos commandes, vos livraisons')
@section('description', 'Boutique en ligne, commandes WhatsApp, Facebook et TikTok, un assistant qui remplit vos commandes et vos fiches produits, et des entreprises de livraison fiables connectées en un clic. '.\App\Support\TrialOffer::duration().' de l\'offre Pro offert.')

@php
    $whatsapp = preg_replace('/\D+/', '', (string) config('platform.support_whatsapp'));
    $whatsappUrl = $whatsapp !== '' ? 'https://wa.me/'.$whatsapp.'?text='.rawurlencode('Bonjour, je souhaite en savoir plus sur Jibiat Marchand pour ma boutique.') : null;
    // Essai offert à chaque nouvelle boutique : « 1 mois de l'offre Pro »
    $trial = \App\Support\TrialOffer::duration();
    $trialPlan = \App\Support\TrialOffer::planName();
    // Actions de l'assistant par offre publique, telles que réglées dans la console
    $aiActions = $plans->filter(fn ($p) => array_key_exists('ai_actions_per_month', $p->limits ?? []) && ($p->limits['ai_actions_per_month'] ?? 0) !== 0)
        ->map(fn ($p) => ($p->limits['ai_actions_per_month'] === null ? 'illimitées' : number_format($p->limits['ai_actions_per_month'], 0, ',', ' ')).' en '.$p->name);
    $trialActions = $plans->firstWhere('code', config('platform.trial_plan'))?->limits['ai_actions_per_month'] ?? null;
    // Montant sans coupure de ligne (espaces insécables)
    $f = fn (int $amount) => number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}F";
    // Prix d'entrée des offres payantes, au mois et à la semaine
    $fromMonthly = $plans->where('monthly_price', '>', 0)->min('monthly_price');
    $fromWeekly = $plans->where('weekly_price', '>', 0)->min('weekly_price');
    $aiPack = config('platform.ai_pack');
    $partnerTrial = \App\Support\TrialOffer::duration(\App\Support\TrialOffer::partnerDays());
    $faq = [
        ['Combien ça coûte ?', 'Vous commencez avec '.$trial.' de l\'offre '.$trialPlan.' offert : l\'assistant, WhatsApp automatique, les statistiques avancées, le paiement en ligne, sans carte bancaire. Ensuite, l\'offre Gratuit reste disponible'.($fromMonthly ? ', et les offres payantes commencent à '.$f($fromMonthly).' par mois'.($fromWeekly ? ', ou '.$f($fromWeekly).' par semaine' : '') : '').'.'],
        ['Comment je paie mon abonnement ?', 'Sans carte bancaire : par Wave, Orange Money, MTN ou Moov, depuis votre téléphone. À la semaine, au mois ou à l\'année (12 mois pour le prix de 10). Vous recevez une facture à chaque paiement, et rien n\'est prélevé tout seul.'],
        ['Et si j\'utilise beaucoup l\'assistant ?', 'Quand les actions de votre offre sont épuisées, rechargez '.$aiPack['actions'].' actions pour '.$f($aiPack['price']).' par Mobile Money, sans changer d\'offre. Elles ne périment pas.'],
        ['Mon entreprise de livraison m\'a envoyé un lien. Qu\'est-ce que j\'y gagne ?', 'Si elle est dans l\'annuaire Jibiat, son lien vous offre '.$partnerTrial.' de l\'offre '.$trialPlan.' au lieu de '.$trial.', et vous la connectez en un clic.'],
        ['Que fait l\'assistant ?', 'Il transforme le message WhatsApp d\'un client (même mal écrit, ou en capture d\'écran) en commande, écrit la fiche d\'un produit à partir d\'une photo, prépare vos réponses aux clients avec vos vrais prix et votre stock, rédige vos publications et répond à vos questions sur vos chiffres. Rien n\'est envoyé ni enregistré sans vous.'],
        ['La boutique en ligne est-elle comprise ?', 'Oui, dans toutes les offres, même Gratuit : vos produits en ligne, le panier, la commande et le suivi pour vos clients, à l\'adresse votre-nom.'.config('platform.domain').'. Les offres payantes ajoutent le paiement en ligne, et Pro et Business votre propre nom de domaine.'],
        ['Et à la fin du mois d\'essai ?', 'Rien n\'est prélevé : vous choisissez l\'offre qui vous convient, ou votre espace marchand passe sur l\'offre Gratuit. Vos produits, vos clients et vos commandes restent là.'],
        ['Comment je trouve une entreprise de livraison ?', 'Dans Livraisons › Entreprises de livraison, l\'annuaire Jibiat montre les entreprises près de chez vous avec leur réussite et leur délai réels des 90 derniers jours. Vous touchez « Connecter » et c\'est fait.'],
        ['Et si j\'ai déjà mes propres livreurs ?', 'Gardez-les : ils ont leur application, avec leurs missions, les encaissements et les preuves de livraison. Vous pouvez mélanger vos livreurs et des entreprises de livraison.'],
        ['Je vends seulement sur WhatsApp et Facebook. C\'est pour moi ?', 'Oui. Collez le message du client : la commande est prête. Vous avez aussi une boutique en ligne à partager, sans rien installer.'],
        ['Comment je suis payé ?', 'À la livraison en espèces, ou en ligne avec Wave, Orange Money, MTN, Moov ou par carte. Les reversements des entreprises de livraison sont rapprochés de vos commandes automatiquement.'],
        ['Ma boutique en ligne vend-elle la nuit ?', 'Oui. Elle reste ouverte jour et nuit : le client choisit, commande et peut payer par Wave, Orange Money, MTN, Moov ou carte. Il reçoit sa confirmation sur WhatsApp, et vous retrouvez la commande le matin, prête à partir.'],
        ['Faut-il un ordinateur ?', 'Non. Tout se fait depuis votre téléphone, en 5 minutes pour commencer.'],
    ];
@endphp

@section('og')
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="Jibiat Marchand">
    <meta property="og:title" content="Jibiat Marchand · Vendez partout, livrez sans souci">
    <meta property="og:description" content="Un assistant qui remplit vos commandes WhatsApp et vos fiches produits, une boutique en ligne, votre stock et des livreurs fiables en un clic. {{ ucfirst($trial) }} de l'offre {{ $trialPlan }} offert.">
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
    <a href="{{ $signupUrl }}" class="block bg-amber-300 px-4 py-2 text-center text-sm font-semibold text-amber-950 hover:bg-amber-200">
        🎁 {{ $trial }} de l'offre {{ $trialPlan }} offert à chaque nouvel espace marchand · Sans carte bancaire <span class="underline">J'en profite</span>
    </a>
    <header class="sticky top-0 z-40 border-b border-slate-100 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
            <a href="/" class="flex items-center gap-2 font-semibold">
                <img src="/brand/logo-256.png" alt="" class="h-9 w-9" width="36" height="36">
                <span>Jibiat <span class="text-emerald-700">Marchand</span></span>
            </a>
            <nav class="hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex" aria-label="Navigation principale">
                <a href="#assistant" class="hover:text-slate-900">Assistant</a>
                <a href="#boutique" class="hover:text-slate-900">Boutique</a>
                <a href="#livraison" class="hover:text-slate-900">Livraison</a>
                <a href="#fonctions" class="hover:text-slate-900">Fonctions</a>
                <a href="#tarifs" class="hover:text-slate-900">Tarifs</a>
                <a href="#questions" class="hover:text-slate-900">Questions</a>
            </nav>
            <div class="flex items-center gap-2 text-sm">
                <a href="{{ $loginUrl }}" class="btn-secondary">Se connecter</a>
                <a href="{{ $signupUrl }}" class="btn-brand">Créer mon espace marchand</a>
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
                        Une boutique en ligne qui vend même quand vous dormez, vos commandes WhatsApp, Facebook et TikTok, votre stock et vos livraisons
                        dans une seule application, depuis votre téléphone. Un assistant qui remplit vos commandes et vos fiches produits à votre place.
                        Et des entreprises de livraison fiables, connectées en un clic.
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ $signupUrl }}" class="btn-brand bg-white px-6 py-3 text-base text-emerald-900 hover:bg-emerald-50">Créer mon espace marchand gratuitement</a>
                        @if ($whatsappUrl)
                            <a href="{{ $whatsappUrl }}" class="btn-secondary border-white/30 bg-transparent px-6 py-3 text-base text-white hover:bg-white/10" target="_blank" rel="noopener">Poser une question sur WhatsApp</a>
                        @endif
                    </div>
                    <p class="mt-3 text-sm text-emerald-100/80">5 minutes pour commencer · <strong class="text-white">{{ $trial }} de l'offre {{ $trialPlan }} offert</strong> · Sans carte bancaire</p>
                </div>
                <div class="flex justify-center">
                    <video class="w-full max-w-[20rem] rounded-[2rem] shadow-2xl ring-1 ring-white/15" poster="/videos/marchand-assistant-v.jpg" width="1080" height="1920" autoplay muted loop playsinline preload="metadata" aria-label="Vidéo : l'assistant de Jibiat Marchand remplit vos commandes, vos fiches produits et vos réponses">
                        <source src="/videos/marchand-assistant-v.mp4" type="video/mp4">
                    </video>
                </div>
            </div>
        </section>

        {{-- L'assistant (intelligence artificielle) --}}
        <section id="assistant" class="scroll-mt-20 py-16 sm:py-20">
            <div class="mx-auto max-w-6xl px-4">
                <div class="mx-auto max-w-2xl text-center">
                    <p class="font-semibold text-emerald-700">Votre assistant, compris dans l'offre</p>
                    <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Il fait le travail répétitif. Vous vendez.</h2>
                    <p class="mt-4 text-lg text-slate-600">
                        Un assistant d'intelligence artificielle travaille dans votre espace marchand, avec vos vrais produits, vos prix, votre stock et vos frais de livraison.
                        Il propose, vous vérifiez : rien n'est envoyé ni enregistré sans vous.
                    </p>
                </div>
                <div class="mt-14 space-y-16 sm:space-y-24">
                    @foreach ([
                        ['marchand-ia-commande', '💬', 'Un message WhatsApp devient une commande', 'Collez le message du client, même mal écrit, en plusieurs fois ou en capture d\'écran : numéro, nom, quartier, articles et frais de livraison se remplissent.'],
                        ['marchand-ia-photo', '📸', 'Une photo devient une fiche produit', 'Prenez le produit en photo : nom, description, tailles et couleurs, mots-clés pour Google. Vous fixez le prix et le stock.'],
                        ['marchand-ia-reponses', '⚡', 'Vos réponses aux clients, prêtes', 'Taille disponible, prix, frais de livraison : l\'assistant prépare la réponse avec vos vrais chiffres. Vous relisez et vous envoyez.'],
                        ['marchand-ia-reseaux', '📣', 'Vos publications écrites pour vous', 'Trois textes prêts pour Facebook, Instagram et TikTok, avec le bon prix et les hashtags, en français simple ou en nouchi.'],
                        ['marchand-ia-chiffres', '📊', 'Vos chiffres, en une question', '« Qu\'est-ce qui se vend le mieux cette semaine ? » L\'assistant lit vos ventes et votre stock et vous répond simplement.'],
                    ] as $i => [$video, $icon, $title, $text])
                        <div class="grid items-center gap-8 lg:grid-cols-2 lg:gap-16">
                            <div @class(['flex justify-center', 'lg:order-2' => $i % 2 === 1])>
                                <video class="w-full max-w-[20rem] rounded-[2rem] bg-slate-900 shadow-2xl ring-1 ring-slate-200" poster="/videos/{{ $video }}-v.jpg" width="1080" height="1920" controls playsinline preload="none" aria-label="Vidéo : {{ $title }}">
                                    <source src="/videos/{{ $video }}-v.mp4" type="video/mp4">
                                </video>
                            </div>
                            <div class="text-center lg:text-left">
                                <span class="text-4xl" aria-hidden="true">{{ $icon }}</span>
                                <h3 class="mt-3 text-2xl font-bold tracking-tight text-balance sm:text-3xl">{{ $title }}</h3>
                                <p class="mt-3 text-lg text-slate-600">{{ $text }}</p>
                            </div>
                        </div>
                    @endforeach
                    <div class="mx-auto max-w-2xl rounded-3xl bg-emerald-900 p-8 text-center text-white">
                        <p class="text-sm font-semibold uppercase tracking-wide text-emerald-300">Combien d'actions ?</p>
                        @if ($aiActions->isNotEmpty())
                            <p class="mt-3 text-2xl font-bold">Par mois : {{ $aiActions->join(', ', ' et ') }}.</p>
                        @endif
                        <p class="mt-3 text-emerald-100">Une action, c'est une commande remplie, une fiche écrite, une réponse ou une question. Avec l'essai, vous avez {{ $trialActions ? 'les '.number_format($trialActions, 0, ',', ' ').' actions' : 'l\'assistant' }} de l'offre {{ $trialPlan }} dès le premier jour.</p>
                        <a href="{{ $signupUrl }}" class="btn-brand mt-6 bg-white text-emerald-900 hover:bg-emerald-50">Essayer l'assistant</a>
                    </div>
                </div>
            </div>
        </section>

        {{-- La boutique en ligne, ouverte jour et nuit --}}
        <section id="boutique" class="scroll-mt-20 bg-slate-950 py-16 text-white sm:py-20">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 lg:grid-cols-2">
                <div class="flex justify-center lg:order-2">
                    <video class="w-full max-w-[20rem] rounded-[2rem] shadow-2xl ring-1 ring-white/15" poster="/videos/marchand-boutique-v.jpg" width="1080" height="1920" controls playsinline preload="none" aria-label="Vidéo : votre boutique en ligne et vos commandes">
                        <source src="/videos/marchand-boutique-v.mp4" type="video/mp4">
                    </video>
                </div>
                <div>
                    <p class="font-semibold text-emerald-300">Votre boutique en ligne</p>
                    <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Elle vend même quand vous dormez</h2>
                    <p class="mt-4 text-lg text-slate-300">
                        Pendant que vous dormez, vos clients regardent vos produits, choisissent leur taille et commandent. Le matin,
                        les commandes de la nuit vous attendent, confirmées et prêtes à partir.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            ['🌙', 'Ouverte 24 heures sur 24', 'À votre nom : votre-nom.jibiat-marchand.com, ou votre propre nom de domaine.'],
                            ['💳', 'Le client paie tout seul', 'Wave, Orange Money, MTN, Moov ou carte, en ligne, ou à la livraison s\'il préfère.'],
                            ['✅', 'Confirmée sur WhatsApp', 'Le client reçoit sa confirmation et la confirme d\'un bouton, même à 3 heures du matin.'],
                            ['🛒', 'Les paniers oubliés relancés', 'Un client hésite et part ? Il reçoit un rappel sur WhatsApp avec le lien pour finir sa commande.'],
                            ['📦', 'Le stock suit tout seul', 'Chaque vente le met à jour : vous ne vendez pas ce que vous n\'avez plus.'],
                        ] as [$icon, $title, $text])
                            <li class="flex gap-4">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white/10 text-xl" aria-hidden="true">{{ $icon }}</span>
                                <span><span class="block font-semibold">{{ $title }}</span><span class="block text-slate-300">{{ $text }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ $signupUrl }}" class="btn-brand mt-8 bg-white px-6 py-3 text-base text-emerald-900 hover:bg-emerald-50">Ouvrir ma boutique en ligne</a>
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
                        d'après leurs vrais résultats, et confiez-leur vos colis sans quitter votre espace marchand.
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
                        ['🛍️', 'Votre boutique en ligne', 'Sur votre-nom.jibiat-marchand.com ou votre propre nom de domaine : catalogue, panier, commande et suivi pour vos clients.'],
                        ['✅', 'Confirmation par WhatsApp', 'Le client confirme sa commande d\'un bouton, puis reçoit l\'expédition et la livraison : moins de refus à la porte.'],
                        ['🛒', 'Paniers abandonnés relancés', 'Le client a laissé son panier ? Il reçoit un rappel WhatsApp une heure après, avec le lien pour finir sa commande.'],
                        ['🔁', 'Relances des anciens clients', 'Nouvelle collection, retour d\'un article : un message aux clients qui l\'ont accepté, avec le coût affiché avant l\'envoi.'],
                        ['🎁', 'Fidélité, parrainage et promotions', 'Points de fidélité, codes de parrainage, codes promo, ventes flash et livraison offerte dès un montant.'],
                        ['⭐', 'Avis clients', 'Les avis de vos clients sur votre boutique, pour rassurer ceux qui hésitent.'],
                        ['📈', 'Publicités qui comptent juste', 'Pixel Meta et TikTok, API Conversions et catalogue Facebook et Google : vos publicités savent qui achète vraiment.'],
                        ['📱', 'Ce que rapporte chaque réseau', 'Facebook, Instagram, TikTok : publiez depuis l\'application et voyez les commandes et le chiffre de chaque réseau.'],
                        ['📦', 'Un stock qui se tient tout seul', 'Chaque vente, retour ou réception le met à jour, variante par variante, avec les commandes fournisseurs et les inventaires.'],
                        ['🛵', 'Vos propres livreurs', 'Leur application marche même sans réseau : missions, encaissements, preuves de livraison.'],
                        ['💵', 'Caisse et vente au comptoir', 'L\'argent de votre espace marchand, des livreurs et des entreprises de livraison, au franc près, et la vente en magasin.'],
                        ['💳', 'Paiement mobile money', 'Wave, Orange Money, MTN, Moov ou carte, en ligne ou par QR code à la porte.'],
                        ['🌅', 'Les conseils du jour', 'Chaque matin : quoi racheter, quoi relancer, quoi livrer. Et le rapport du soir par WhatsApp.'],
                        ['👥', 'Votre équipe', 'Chaque membre avec son rôle : vendeuse, préparateur, caissière. Chacun voit ce qu\'il doit voir.'],
                        ['🏬', 'Plusieurs boutiques', 'Avec l\'offre Business, jusqu\'à 3 boutiques sous un seul abonnement, avec une vue d\'ensemble.'],
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
                        ['Créez votre espace marchand', 'Votre nom, votre adresse en ligne et votre téléphone. C\'est tout.'],
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

        {{-- L'essai : un mois de l'offre Pro --}}
        <section id="essai" class="scroll-mt-20 overflow-hidden bg-gradient-to-br from-amber-300 via-amber-200 to-emerald-200 py-16 sm:py-20">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 lg:grid-cols-2">
                <div>
                    <p class="inline-block rounded-full bg-white/70 px-3 py-1 text-sm font-semibold text-amber-900">Offert à chaque nouvel espace marchand</p>
                    <h2 class="mt-4 text-4xl font-bold tracking-tight text-balance text-slate-900 sm:text-5xl">{{ ucfirst($trial) }} de l'offre {{ $trialPlan }}, gratuit</h2>
                    <p class="mt-4 text-lg text-slate-800">Toutes les fonctions de l'offre {{ $trialPlan }} dès la création de votre espace marchand. Sans carte bancaire, sans engagement : à la fin, vous choisissez.</p>
                    <ul class="mt-6 grid gap-3 text-slate-900 sm:grid-cols-2">
                        @foreach ([$trialActions ? number_format($trialActions, 0, ',', ' ').' actions de l\'assistant par mois' : 'L\'assistant', 'Commandes et produits illimités', 'WhatsApp automatique', 'Statistiques avancées', 'Votre nom de domaine', 'Paiement en ligne', 'Livreurs et entreprises de livraison', 'Votre équipe avec ses rôles'] as $item)
                            <li class="flex items-center gap-2 font-medium"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-700 text-xs text-white" aria-hidden="true">✓</span>{{ $item }}</li>
                        @endforeach
                    </ul>
                    <a href="{{ $signupUrl }}" class="btn-brand mt-8 px-8 py-3 text-base">Créer mon espace marchand et essayer {{ $trialPlan }}</a>
                </div>
                <div class="flex justify-center">
                    <video class="w-full max-w-[18rem] rounded-[2rem] shadow-2xl ring-1 ring-black/10" poster="/videos/marchand-essai-pro-v.jpg" width="1080" height="1920" autoplay muted loop playsinline preload="none" aria-label="Vidéo : {{ $trial }} de l'offre {{ $trialPlan }} offert">
                        <source src="/videos/marchand-essai-pro-v.mp4" type="video/mp4">
                    </video>
                </div>
            </div>
        </section>

        {{-- Tarifs (offres de la console) --}}
        @if ($plans->isNotEmpty())
            <section id="tarifs" class="scroll-mt-20 bg-slate-50 py-16 sm:py-20">
                <div class="mx-auto max-w-6xl px-4">
                    <div class="mx-auto max-w-2xl text-center">
                        <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">Des prix simples, en francs CFA</h2>
                        <p class="mt-3 text-lg text-slate-600">{{ ucfirst($trial) }} de l'offre {{ $trialPlan }} offert, puis l'offre de votre choix. Sans engagement.</p>
                        <p class="mt-4 flex flex-wrap justify-center gap-x-4 gap-y-1 text-sm font-medium text-emerald-800">
                            <span>✓ Sans carte bancaire</span><span>✓ Wave, Orange Money, MTN, Moov</span><span>✓ Une facture à chaque paiement</span>
                        </p>
                    </div>
                    <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($plans as $plan)
                            @php
                                $limits = $plan->limits ?? [];
                                $count = fn ($key, $one, $many) => array_key_exists($key, $limits) && $limits[$key] !== null ? ($limits[$key] === 0 ? null : $limits[$key].' '.($limits[$key] > 1 ? $many : $one)) : ucfirst($many).' sans limite';
                            @endphp
                            <div @class(['flex flex-col rounded-2xl bg-white p-6 shadow-sm ring-1', 'ring-2 ring-emerald-600' => $plan->code === 'pro', 'ring-slate-200' => $plan->code !== 'pro'])>
                                <h3 class="flex items-center justify-between gap-2 font-semibold">{{ $plan->name }}@if ($plan->code === config('platform.trial_plan'))<span class="rounded-full bg-amber-300 px-2 py-0.5 text-xs font-semibold text-amber-950">{{ $trial }} offert</span>@endif</h3>
                                <p class="mt-2"><span class="text-3xl font-bold">{{ number_format($plan->monthly_price, 0, ',', ' ') }} F</span><span class="text-slate-500"> / mois</span></p>
                                @if ($plan->weekly_price)
                                    <p class="mt-1 text-sm font-medium text-slate-700">ou {{ $f($plan->weekly_price) }} / semaine</p>
                                @endif
                                @if ($plan->yearly_price && $plan->monthly_price * 12 > $plan->yearly_price)
                                    <p class="mt-1 text-sm text-emerald-700">À l'année : {{ $f($plan->yearly_price) }}, soit {{ $f($plan->monthly_price * 12 - $plan->yearly_price) }} d'économie</p>
                                @endif
                                <ul class="mt-5 space-y-2 text-sm text-slate-700">
                                    <li class="font-medium text-emerald-800">✓ @if (! empty($plan->features['custom_domain']))Boutique en ligne à votre nom de domaine @else Boutique en ligne : <span class="whitespace-nowrap">votre-nom.{{ config('platform.domain') }}</span>@endif</li>
                                    <li>✓ {{ ($limits['orders_per_month'] ?? null) !== null ? $limits['orders_per_month'].' commandes par mois' : 'Commandes illimitées' }}</li>
                                    <li>✓ {{ ($limits['products'] ?? null) !== null ? $limits['products'].' produits' : 'Produits illimités' }}</li>
                                    <li>{{ $count('carrier_connections', 'entreprise de livraison', 'entreprises de livraison') ? '✓ '.$count('carrier_connections', 'entreprise de livraison connectée', 'entreprises de livraison connectées') : '— Vos livreurs seulement' }}</li>
                                    <li>✓ {{ $count('couriers', 'livreur', 'livreurs') }}</li>
                                    <li>{{ ! empty($plan->features['online_payment']) ? '✓ Paiement en ligne Wave, Orange Money…' : '— Paiement à la livraison seulement' }}</li>
                                    @if (array_key_exists('ai_actions_per_month', $limits))
                                        <li>{{ $limits['ai_actions_per_month'] === 0 ? '— Sans assistant' : '✓ '.($limits['ai_actions_per_month'] === null ? 'Assistant illimité' : number_format($limits['ai_actions_per_month'], 0, ',', ' ').' actions de l\'assistant') }}</li>
                                    @endif
                                    @if (! empty($plan->features['whatsapp_api']))
                                        <li>✓ WhatsApp automatique</li>
                                    @endif
                                    @if (! empty($plan->features['multi_store']))
                                        <li>✓ {{ ($limits['stores'] ?? null) !== null ? 'Jusqu\'à '.$limits['stores'].' boutiques' : 'Toutes vos boutiques' }} sous un seul abonnement</li>
                                    @endif
                                </ul>
                                <div class="mt-auto pt-6"><a href="{{ $signupUrl }}" class="btn-brand w-full">Commencer</a></div>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-6 text-center text-sm text-slate-600">Besoin de plus d'assistant ? Rechargez {{ $aiPack['actions'] }} actions pour {{ $f($aiPack['price']) }}, sans changer d'offre.</p>
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
                <h2 class="text-3xl font-bold tracking-tight text-balance sm:text-4xl">Votre boutique en 5 minutes, un assistant qui travaille pour vous, vos livraisons en un clic</h2>
                <a href="{{ $signupUrl }}" class="btn-brand mt-8 bg-white px-8 py-3 text-base text-emerald-900 hover:bg-emerald-50">Créer mon espace marchand gratuitement</a>
                <p class="mt-3 font-semibold text-amber-200">{{ ucfirst($trial) }} de l'offre {{ $trialPlan }} offert · Sans carte bancaire</p>
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
