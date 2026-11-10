@extends('layouts.page')

{{-- Vidéo envoyée par lien sur WhatsApp : lue tout de suite dans le navigateur, au lieu d'un fichier à télécharger --}}
@section('title', $title.' · Jibiat Marchand')
@section('description', $title)

@section('og')
    <meta property="og:type" content="video.other">
    <meta property="og:site_name" content="Jibiat Marchand">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:image" content="{{ url($poster) }}">
@endsection

@section('content')
    <main class="min-h-screen bg-slate-950 px-4 py-6 text-white">
        <div class="mx-auto max-w-md">
            <a href="/" class="mb-4 flex items-center gap-2 font-bold"><img src="/brand/logo-256.png" alt="" class="h-8 w-8">Jibiat Marchand</a>
            <h1 class="mb-3 text-xl font-black">{{ $title }}</h1>
            <video src="{{ $src }}" poster="{{ $poster }}" controls autoplay playsinline preload="metadata" class="w-full rounded-2xl bg-black"></video>
            <p class="mt-4 text-sm text-slate-300">Une question ? Écris-nous sur WhatsApp, on t'aide.</p>
        </div>
    </main>
@endsection
