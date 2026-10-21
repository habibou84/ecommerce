@extends('layouts.page')

@section('title', 'Bien démarrer · Les étapes essentielles · Jibiat Marchand')
@section('description', 'Les étapes essentielles pour commencer avec Jibiat Marchand : une courte vidéo par étape, de la création de votre espace marchand à votre première commande.')

@section('og')
    <link rel="canonical" href="{{ url('/bien-demarrer') }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="Jibiat Marchand">
    <meta property="og:title" content="Bien démarrer avec Jibiat Marchand : {{ count($steps) }} étapes en vidéo">
    <meta property="og:description" content="De la création de votre espace marchand à votre première commande, une courte vidéo par étape.">
    <meta property="og:url" content="{{ url('/bien-demarrer') }}">
    <meta property="og:image" content="{{ url($steps[0]['poster']) }}">
@endsection

@section('content')
    <header class="border-b border-slate-100 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
            <a href="/" class="flex items-center gap-2 font-bold text-slate-900"><img src="/brand/logo-256.png" alt="" class="h-8 w-8">Jibiat Marchand</a>
            <a href="/creer-ma-boutique" class="btn-brand px-4 py-2 text-sm">Créer mon espace marchand</a>
        </div>
    </header>

    <main>
        <section class="bg-gradient-to-b from-emerald-900 to-emerald-800 text-white">
            <div class="mx-auto max-w-3xl px-4 py-14 text-center">
                <p class="inline-block rounded-full bg-white/10 px-3 py-1 text-sm font-medium text-emerald-100 ring-1 ring-white/15">Bien démarrer</p>
                <h1 class="mt-4 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Les étapes essentielles pour commencer</h1>
                <p class="mt-4 text-lg text-emerald-50/90">Une courte vidéo par étape, moins d'une minute chacune. Suivez-les dans l'ordre : en une heure, votre boutique est prête à vendre.</p>
                <p class="mt-3 text-sm text-emerald-100/80">Dans l'application, le guide « Bien démarrer » (menu Réglages) reprend ces étapes et coche celles qui sont faites.</p>
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-4 py-12">
            <ol class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($steps as $step)
                    <li id="etape-{{ $step['n'] }}" class="scroll-mt-6 flex flex-col">
                        <video class="aspect-[9/16] w-full max-w-[20rem] self-center rounded-[1.75rem] bg-slate-900 shadow-xl ring-1 ring-slate-200" poster="{{ $step['poster'] }}" width="1080" height="1920" controls playsinline preload="none" aria-label="Vidéo : étape {{ $step['n'] }}, {{ $step['title'] }}">
                            <source src="{{ $step['video'] }}" type="video/mp4">
                        </video>
                        <div class="mt-4">
                            <p class="text-sm font-semibold text-emerald-700">Étape {{ $step['n'] }} sur {{ count($steps) }} · {{ $step['seconds'] }} s</p>
                            <h2 class="mt-1 text-xl font-bold tracking-tight">{{ $step['title'] }}</h2>
                            <p class="mt-2 text-slate-600">{{ $step['text'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>

            <div class="mx-auto mt-16 max-w-2xl rounded-3xl bg-emerald-900 p-8 text-center text-white">
                <h2 class="text-2xl font-bold">Prêt à commencer ?</h2>
                <p class="mt-2 text-emerald-100">{{ ucfirst(\App\Support\TrialOffer::duration()) }} de l'offre {{ \App\Support\TrialOffer::planName() }} offert, sans carte bancaire.</p>
                <a href="/creer-ma-boutique" class="btn-brand mt-6 bg-white text-emerald-900 hover:bg-emerald-50">Créer mon espace marchand gratuitement</a>
            </div>
            <p class="mt-10 text-center text-sm text-slate-500"><a href="/" class="underline">Accueil</a> · <a href="/cgu" class="underline">Conditions</a> · <a href="/confidentialite" class="underline">Confidentialité</a></p>
        </section>
    </main>
@endsection
