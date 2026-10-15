@extends('layouts.page')

@section('title', 'Préproduction · Jibiat Marchand')

@section('content')
    <main class="mx-auto flex min-h-screen max-w-sm flex-col justify-center px-4">
        <div class="card p-6">
            <h1 class="text-xl font-semibold">Préproduction</h1>
            <p class="mt-1 text-sm text-slate-600">Site d'essai de Jibiat Marchand, réservé à l'équipe. Saisissez le code d'accès.</p>
            @if ($error)
                <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $error }}</p>
            @endif
            <form method="POST" action="{{ $action }}" class="mt-4 space-y-3">
                <input type="hidden" name="retour" value="{{ $back }}">
                <input type="password" name="code" required autofocus autocomplete="current-password" aria-label="Code d'accès"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Code d'accès">
                <button type="submit" class="btn-brand w-full py-2">Entrer</button>
            </form>
        </div>
    </main>
@endsection
