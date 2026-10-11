@extends('layouts.page')

@section('title', $store->name)
@section('description', $store->tagline ?: $store->name)

@section('og')
    <meta property="og:title" content="{{ $store->name }}">
    <meta property="og:description" content="{{ $store->tagline ?: 'Boutique en ligne' }}">
    <meta property="og:type" content="website">
@endsection

@section('content')
    {{-- Boutique publique : construite en phase 3 (thèmes, catalogue, panier) --}}
    <main class="mx-auto flex min-h-screen max-w-xl flex-col items-center justify-center px-4 text-center">
        <h1 class="text-3xl font-bold">{{ $store->name }}</h1>
        @if ($store->tagline)
            <p class="mt-2 text-slate-600">{{ $store->tagline }}</p>
        @endif
        <p class="mt-8 text-slate-600">Notre boutique en ligne ouvre bientôt.</p>
        @if ($store->whatsapp_phone)
            <a href="https://wa.me/{{ ltrim($store->whatsapp_phone, '+') }}" class="btn-brand mt-6 px-6 py-3">Commander sur WhatsApp</a>
        @endif
    </main>
@endsection
