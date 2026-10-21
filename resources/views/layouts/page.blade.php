<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>@yield('title', 'Jibiat Marchand')</title>
    <meta name="description" content="@yield('description', 'Créez votre boutique en ligne, gérez vos commandes et livrez avec vos livreurs ou une société de livraison.')">
    <meta name="theme-color" content="#017c40">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/brand/logo-256.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @hasSection('og')
        @yield('og')
    @endif
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-white text-slate-900 antialiased">
    @yield('content')
</body>
</html>
