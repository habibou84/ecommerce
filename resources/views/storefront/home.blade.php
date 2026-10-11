@extends('layouts.storefront')

@push('head')
    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'Store', 'name' => $store->name, 'url' => $baseUrl, 'description' => $store->tagline, 'telephone' => $store->phone, 'address' => $store->address, 'image' => $logo], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
    @php($settings = $theme['settings'])
    <section class="relative -mx-4 mb-10 overflow-hidden {{ $theme['hero_image_urls'] ? 'text-white' : 'bg-brand-soft' }}">
        @if ($theme['hero_image_urls'])
            <img src="{{ $theme['hero_image_urls']['medium'] }}" srcset="{{ $theme['hero_image_urls']['medium'] }} 800w, {{ $theme['hero_image_urls']['large'] }} 1600w"
                 sizes="100vw" alt="" class="absolute inset-0 h-full w-full object-cover" fetchpriority="high">
            <div class="absolute inset-0 bg-black/40"></div>
        @endif
        <div class="relative mx-auto max-w-3xl px-6 py-16 text-center sm:py-24">
            <h1 class="text-3xl font-bold sm:text-5xl">{{ $settings['hero_title'] ?: $store->name }}</h1>
            @if ($settings['hero_subtitle'] ?: $store->tagline)
                <p class="mx-auto mt-4 max-w-xl text-lg {{ $theme['hero_image_urls'] ? 'text-white/90' : 'text-slate-600' }}">{{ $settings['hero_subtitle'] ?: $store->tagline }}</p>
            @endif
            <a href="/produits" class="btn-store mt-8">{{ $settings['hero_button'] ?: 'Voir la boutique' }}</a>
        </div>
    </section>

    @if ($featured && $featured['cards']->isNotEmpty())
        <section class="mb-12">
            <div class="mb-4 flex items-end justify-between gap-4">
                <h2 class="text-2xl font-bold">{{ $featured['collection']->title }}</h2>
                <a href="/collections/{{ $featured['collection']->slug }}" class="shrink-0 text-sm text-brand underline">Tout voir</a>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-6 lg:grid-cols-4">
                @foreach ($featured['cards'] as $card)
                    @include('storefront.partials.card', ['eager' => $loop->index < 2])
                @endforeach
            </div>
        </section>
    @endif

    @if ($collections->isNotEmpty())
        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Nos collections</h2>
            <div class="flex gap-2 overflow-x-auto pb-2">
                @foreach ($collections as $collection)
                    <a href="/collections/{{ $collection->slug }}" class="shrink-0 rounded-full border border-brand px-4 py-2 text-sm text-brand hover:bg-brand-soft">{{ $collection->title }}</a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($newArrivals->isNotEmpty())
        <section class="mb-12">
            <div class="mb-4 flex items-end justify-between gap-4">
                <h2 class="text-2xl font-bold">Nouveautés</h2>
                <a href="/produits" class="shrink-0 text-sm text-brand underline">Tous les produits</a>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-6 lg:grid-cols-4">
                @foreach ($newArrivals as $card)
                    @include('storefront.partials.card', ['eager' => ! $featured && $loop->index < 2])
                @endforeach
            </div>
        </section>
    @endif

    @if (! $featured && $newArrivals->isEmpty())
        <p class="py-12 text-center text-slate-500">Nos produits arrivent bientôt.</p>
    @endif
@endsection
