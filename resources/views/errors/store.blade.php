@extends('layouts.page')

@section('title', 'Jibiat Marchand')

@section('content')
    <main class="mx-auto flex min-h-screen max-w-xl flex-col items-center justify-center px-4 text-center">
        <h1 class="text-2xl font-semibold">{{ $message }}</h1>
        <p class="mt-3 text-slate-600">Vérifiez l'adresse, ou créez votre propre espace marchand.</p>
        <a href="{{ $platformUrl }}" class="btn-brand mt-6 px-6 py-3">Aller sur Jibiat Marchand</a>
    </main>
@endsection
