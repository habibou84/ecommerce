@extends('layouts.page')

@section('title', 'Jibiat Marchand — votre boutique en ligne, vos commandes, vos livraisons')

@section('og')
    <meta property="og:title" content="Jibiat Marchand">
    <meta property="og:description" content="Vendez partout, gérez tout au même endroit, livrez avec vos livreurs ou une société de livraison.">
    <meta property="og:type" content="website">
@endsection

@section('content')
    <header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
        <a href="/" class="flex items-center gap-2 font-semibold">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-emerald-700 text-white">J</span>
            <span>Jibiat <span class="text-emerald-700">Marchand</span></span>
        </a>
        <nav class="flex items-center gap-2 text-sm">
            <a href="{{ $loginUrl }}" class="btn-secondary">Se connecter</a>
            <a href="{{ $signupUrl }}" class="btn-brand hidden sm:inline-flex">Créer ma boutique</a>
        </nav>
    </header>

    <main>
        <section class="mx-auto max-w-6xl px-4 pb-16 pt-10 sm:pt-16">
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Pour les e-commerçants</p>
            <h1 class="mt-3 max-w-3xl text-4xl font-bold leading-tight sm:text-5xl">
                Vendez partout. Gérez tout au même endroit. Livrez comme vous voulez.
            </h1>
            <p class="mt-5 max-w-2xl text-lg text-slate-600">
                Votre boutique en ligne sur <strong>votre-nom.jibiat-marchand.com</strong>, vos commandes WhatsApp, Facebook et TikTok,
                votre stock, vos livreurs et les sociétés de livraison partenaires, dans une seule application, depuis votre téléphone.
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="{{ $signupUrl }}" class="btn-brand px-6 py-3 text-base">Créer ma boutique gratuitement</a>
                <a href="{{ $loginUrl }}" class="btn-secondary px-6 py-3 text-base">J'ai déjà une boutique</a>
            </div>
            <p class="mt-3 text-sm text-slate-500">5 minutes, depuis votre téléphone. {{ config('platform.trial_days') }} jours d'essai de toutes les fonctions.</p>
        </section>

        <section class="border-t border-slate-100 bg-slate-50">
            <div class="mx-auto grid max-w-6xl gap-6 px-4 py-14 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['Catalogue à variantes', 'Tailles, couleurs, matières : chaque variante a son prix, sa photo et son stock.'],
                    ['Commandes de tous vos canaux', 'Site, WhatsApp, Facebook, TikTok, téléphone : collez le message du client, la commande est prête.'],
                    ['Confirmation avant envoi', 'Une file « à confirmer » pour appeler vos clients et réduire les refus à la livraison.'],
                    ['Vos livreurs', 'Une application pour vos livreurs : missions, encaissements, preuves de livraison, versements.'],
                    ['Sociétés de livraison', 'Envoyez vos colis aux sociétés qui utilisent Jibiat, suivez-les et contrôlez leurs reversements.'],
                    ['Paiement à la livraison ou en ligne', 'Wave, Orange Money, MTN, Moov ou carte, avec le prestataire qui vous coûte le moins.'],
                ] as [$title, $text])
                    <div class="rounded-xl border border-slate-200 bg-white p-5">
                        <h2 class="font-semibold">{{ $title }}</h2>
                        <p class="mt-2 text-sm text-slate-600">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    </main>

    <footer class="mx-auto max-w-6xl px-4 py-8 text-sm text-slate-500">
        © {{ date('Y') }} Jibiat Marchand · <a href="/cgu" class="hover:underline">Conditions d'utilisation et de vente</a> · <a href="/confidentialite" class="hover:underline">Confidentialité</a>
    </footer>
@endsection
