@extends('layouts.page')

{{-- Connexion Facebook ouverte à côté de l'application : le résultat est déjà reçu par l'application. Application
     Android : le bouton (et un essai automatique) la rouvre là où elle était (lien « intent » de Chrome). --}}
@section('title', 'Facebook relié · Jibiat Marchand')
@section('description', 'Retourne dans Jibiat Marchand.')

@php($intent = 'intent:#Intent;action=android.intent.action.MAIN;category=android.intent.category.LAUNCHER;package=com.jibiat.marchand;end')

@section('content')
    <main class="mx-auto grid min-h-[70vh] max-w-sm place-items-center px-4 text-center">
        <div class="space-y-3">
            <p class="text-5xl" aria-hidden="true">✅</p>
            <h1 class="text-2xl font-black">C'est fait</h1>
            @if ($app)
                <p class="text-lg text-slate-600">Retourne dans l'application : la suite s'affiche toute seule.</p>
                <a href="{{ $intent }}" class="inline-block rounded-xl bg-emerald-700 px-5 py-3 text-base font-bold text-white">Retourner dans l'application</a>
            @else
                <p class="text-lg text-slate-600">Retourne dans Jibiat Marchand : la suite s'affiche toute seule.</p>
                <button type="button" class="rounded-xl bg-emerald-700 px-5 py-3 text-base font-bold text-white" onclick="window.close()">Fermer cette fenêtre</button>
            @endif
        </div>
    </main>
    <script>
        @if ($app)
            setTimeout(() => { window.location.href = @json($intent) }, 400)
        @else
            setTimeout(() => window.close(), 800)
        @endif
    </script>
@endsection
