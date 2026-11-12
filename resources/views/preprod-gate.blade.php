@extends('layouts.page')

@section('title', 'Jibiat Marchand')

@section('content')
    <main class="mx-auto flex min-h-screen max-w-sm flex-col justify-center px-4">
        <div class="card p-6">
            @if ($error)
                <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $error }}</p>
            @endif
            <form method="POST" action="{{ $action }}" class="space-y-3">
                <input type="hidden" name="retour" value="{{ $back }}">
                <div class="relative">
                    <input id="code" type="password" name="code" required autofocus autocomplete="current-password" aria-label="Code d'accès"
                        class="w-full rounded-lg border border-slate-300 py-2 pl-3 pr-11" placeholder="Code d'accès">
                    {{-- L'œil : montrer ou cacher le code tapé --}}
                    <button type="button" id="eye" class="absolute inset-y-0 right-0 grid w-11 place-items-center text-slate-500" aria-label="Afficher le code" aria-pressed="false"
                        onclick="const i=document.getElementById('code');const show=i.type==='password';i.type=show?'text':'password';this.setAttribute('aria-pressed',show);this.setAttribute('aria-label',show?'Cacher le code':'Afficher le code');this.querySelector('.on').classList.toggle('hidden',show);this.querySelector('.off').classList.toggle('hidden',!show);i.focus()">
                        <svg class="on h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="off hidden h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.6 18.6 0 0 1 5.1-5.9M9.9 5.2A9.6 9.6 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.2 3.2M14.1 14.1a3 3 0 1 1-4.2-4.2M2 2l20 20"/></svg>
                    </button>
                </div>
                <button type="submit" class="btn-brand w-full py-2">Entrer</button>
            </form>
        </div>
    </main>
@endsection
