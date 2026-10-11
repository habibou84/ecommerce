@extends('layouts.page')

@section('content')
    <main class="mx-auto max-w-3xl px-4 py-10">
        <a href="/" class="text-sm text-slate-500 hover:text-emerald-700">← Jibiat Marchand</a>
        <h1 class="mt-4 text-3xl font-bold">@yield('heading')</h1>
        <p class="mt-2 text-sm text-slate-500">Version du {{ \App\Http\Controllers\LegalController::VERSION }}</p>
        {{-- Brouillon : à faire relire par un juriste et compléter (identité de l'éditeur) avant le lancement --}}
        <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">Document provisoire, en cours de validation juridique.</div>
        <div class="legal mt-8 space-y-4 leading-relaxed text-slate-700 [&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-slate-900 [&_li]:ml-5 [&_li]:list-disc">
            @yield('body')
        </div>
        <p class="mt-10 text-sm text-slate-500"><a href="/cgu" class="underline">Conditions d'utilisation et de vente</a> · <a href="/confidentialite" class="underline">Confidentialité</a></p>
    </main>
@endsection
