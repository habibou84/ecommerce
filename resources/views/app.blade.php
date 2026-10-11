<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#047857">
    <title>{{ $boot['store']['name'] ?? 'Jibiat Marchand' }}</title>
    @if (request()->is('livreur*'))
        {{-- Application livreur installable sur le téléphone --}}
        <link rel="manifest" href="/livreur/manifest.webmanifest">
        <meta name="apple-mobile-web-app-capable" content="yes">
    @endif
    <script>window.__BOOT__ = @json($boot);</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <div id="app"></div>
</body>
</html>
