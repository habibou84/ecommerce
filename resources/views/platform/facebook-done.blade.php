@extends('layouts.page')

{{-- Connexion Facebook ouverte par-dessus l'application installée : le résultat est déjà reçu par l'application --}}
@section('title', 'Facebook relié · Jibiat Marchand')
@section('description', 'Retourne dans Jibiat Marchand.')

@section('content')
    <main class="mx-auto grid min-h-[70vh] max-w-sm place-items-center px-4 text-center">
        <div class="space-y-3">
            <p class="text-5xl" aria-hidden="true">✅</p>
            <h1 class="text-2xl font-black">C'est fait</h1>
            <p class="text-lg text-slate-600">Retourne dans Jibiat Marchand : la suite s'affiche toute seule.</p>
            <button type="button" class="rounded-xl bg-emerald-700 px-5 py-3 text-base font-bold text-white" onclick="window.close()">Fermer cette fenêtre</button>
        </div>
    </main>
    <script>setTimeout(() => window.close(), 800)</script>
@endsection
