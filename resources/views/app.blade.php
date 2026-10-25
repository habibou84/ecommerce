<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#017c40">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/brand/logo-256.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <title>{{ $boot['store']['name'] ?? 'Jibiat Marchand' }}</title>
    @if (request()->is('livreur*'))
        {{-- Application livreur installable sur le téléphone --}}
        <link rel="manifest" href="/livreur/manifest.webmanifest">
        <meta name="apple-mobile-web-app-capable" content="yes">
    @elseif (request()->is('gestion*') && isset($boot['store']))
        {{-- Gestion installable sur l'écran d'accueil (Android : « Installer l'application » ; iPhone : Partager › Sur l'écran d'accueil) --}}
        <link rel="manifest" href="/gestion/manifest.webmanifest">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ \Illuminate\Support\Str::limit($boot['store']['name'], 12, '') }}">
    @elseif (request()->is('console*'))
        {{-- Console installable (ordinateur : icône d'installation de la barre d'adresse ; téléphone : « Installer l'application ») --}}
        <link rel="manifest" href="/console/manifest.webmanifest">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="Console">
    @endif
    <script>window.__BOOT__ = @json($boot);</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <div id="app"></div>
</body>
</html>
