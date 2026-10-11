@php
    $settings = $theme['settings'];
    $canonical = $baseUrl.($meta['path'] ?? '/');
    $title = $meta['title'] ?? $store->name;
    $description = \Illuminate\Support\Str::limit($meta['description'] ?? '', 160) ?: $store->name;
    $ogImage = $meta['image'] ?? ($theme['hero_image_urls']['large'] ?? null);
    // Suivi publicitaire : pas pendant l'aperçu d'une boutique encore fermée
    $tracking = $store->storefront_enabled && collect(['meta_pixel_id', 'tiktok_pixel_id', 'ga4_id'])->contains(fn ($k) => $store->marketingId($k));
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if (! empty($meta['noindex']))
        <meta name="robots" content="noindex">
    @endif
    <meta property="og:site_name" content="{{ $store->name }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="{{ $meta['type'] ?? 'website' }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="fr_FR">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    <meta name="theme-color" content="{{ $settings['primary_color'] }}">
    @if ($logo)
        <link rel="icon" href="{{ $logo }}">
    @endif
    <style>:root { {!! $theme['css'] !!} }</style>
    @vite(['resources/css/storefront.css', 'resources/js/storefront.js'])
    @if ($tracking)
        @include('storefront.partials.tracking')
    @endif
    @stack('head')
</head>
<body class="min-h-screen bg-page text-slate-900 antialiased theme-{{ $theme['code'] }} cards-{{ $theme['layout']['cards'] }}">
    @if ($settings['announcement'])
        <div class="bg-brand px-4 py-2 text-center text-sm text-brand-contrast">{{ $settings['announcement'] }}</div>
    @endif

    @include('storefront.partials.header')

    <main id="contenu" class="mx-auto max-w-6xl px-4 pb-16">
        @yield('content')
    </main>

    @include('storefront.partials.footer')

    @if ($whatsapp && $settings['show_whatsapp_button'] && ! $__env->hasSection('no-floating-whatsapp'))
        <a href="{{ $whatsapp }}?text={{ rawurlencode('Bonjour '.$store->name.', ') }}" target="_blank" rel="noopener"
           class="fixed right-4 bottom-4 z-30 grid h-14 w-14 place-items-center rounded-full bg-[#25d366] text-white shadow-lg" aria-label="Nous écrire sur WhatsApp">
            @include('storefront.partials.whatsapp-icon', ['size' => 28])
        </a>
    @endif

    <div id="toast" role="status" aria-live="polite" hidden
         class="fixed inset-x-4 bottom-20 z-40 mx-auto max-w-sm rounded-lg bg-slate-900 px-4 py-3 text-center text-sm text-white shadow-lg"></div>
    @if ($tracking)
        <script>@stack('tracking')</script>
    @endif
</body>
</html>
