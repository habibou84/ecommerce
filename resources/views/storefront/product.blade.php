@extends('layouts.storefront')

@php
    $description = trim(strip_tags(preg_replace('#<br\s*/?>|</p>|</li>#i', "\n", (string) $product->description)));
    $prices = $variants->pluck('price');
    $inStock = fn ($v) => $v['available'] === null || $v['available'] > 0;
    $productUrl = $baseUrl.'/produits/'.$product->slug;
    $showWhatsapp = $whatsapp && $theme['settings']['show_whatsapp_button'];
    $hasChoices = $options->isNotEmpty() && $variants->count() > 1;
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->title,
        'description' => \Illuminate\Support\Str::limit($description, 500),
        'image' => $images->pluck('urls.large')->all(),
        'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand] : null,
        'offers' => [
            '@type' => 'AggregateOffer',
            'priceCurrency' => $store->currency,
            'lowPrice' => $prices->min(),
            'highPrice' => $prices->max(),
            'offerCount' => $variants->count(),
            'availability' => $variants->contains($inStock) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url' => $productUrl,
        ],
    ];
@endphp

@push('head')
    <meta property="product:price:amount" content="{{ $selected['price'] }}">
    <meta property="product:price:currency" content="{{ $store->currency }}">
    <script type="application/ld+json">{!! json_encode(array_filter($jsonLd), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('no-floating-whatsapp', true)

@section('content')
    <nav class="py-3 text-sm text-slate-500" aria-label="Fil d'Ariane">
        <a href="/" class="hover:underline">Accueil</a> › <a href="/produits" class="hover:underline">Boutique</a>
    </nav>

    <div class="grid gap-8 md:grid-cols-2" data-product>
        <div>
            <div class="aspect-square overflow-hidden rounded-xl bg-slate-100">
                @if ($images->isNotEmpty())
                    @php($main = $selected['image'] ?? $images->first()['urls'])
                    <img data-main-image src="{{ $main['medium'] }}" srcset="{{ $main['medium'] }} 800w, {{ $main['large'] }} 1600w"
                         sizes="(min-width: 768px) 50vw, 100vw" alt="{{ $images->first()['alt'] }}" width="800" height="800"
                         fetchpriority="high" class="h-full w-full object-cover">
                @endif
            </div>
            @if ($images->count() > 1)
                <div class="mt-3 flex gap-2 overflow-x-auto">
                    @foreach ($images as $image)
                        <button type="button" data-thumb data-medium="{{ $image['urls']['medium'] }}" data-large="{{ $image['urls']['large'] }}"
                                class="h-16 w-16 shrink-0 overflow-hidden rounded-lg border-2 {{ $loop->first ? 'border-brand' : 'border-transparent' }}" aria-label="Photo {{ $loop->iteration }}">
                            <img src="{{ $image['urls']['thumb'] }}" alt="" width="64" height="64" loading="lazy" class="h-full w-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div>
            <h1 class="text-2xl font-bold sm:text-3xl">{{ $product->title }}</h1>
            <p class="mt-3 flex items-baseline gap-2">
                <span data-price class="text-2xl font-semibold">@money($selected['price'])</span>
                <s data-compare class="text-slate-400" @if (! $selected['compare_at']) hidden @endif>{{ $selected['compare_at'] ? \Jibiat\Socle\Money::format($selected['compare_at']) : '' }}</s>
            </p>

            @if ($hasChoices)
                @foreach ($options as $index => $option)
                    <fieldset class="mt-6">
                        <legend class="mb-2 text-sm font-medium">{{ $option['name'] }} : <span data-option-label="{{ $index }}" class="font-normal text-slate-600">{{ $selected['options'][$index] ?? '' }}</span></legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($option['values'] as $value)
                                <button type="button" class="option-chip" data-option="{{ $index }}" data-value="{{ $value }}"
                                        aria-pressed="{{ ($selected['options'][$index] ?? null) === $value ? 'true' : 'false' }}">{{ $value }}</button>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            @endif

            <p data-stock class="mt-5 text-sm {{ $inStock($selected) ? 'text-emerald-700' : 'text-red-700' }}">
                @if (! $inStock($selected))
                    Épuisé
                @elseif ($selected['available'] !== null && $selected['available'] <= 5)
                    Plus que {{ $selected['available'] }} en stock
                @else
                    En stock
                @endif
            </p>

            <div class="mt-4 flex items-center gap-3">
                <label for="quantite" class="text-sm font-medium">Quantité</label>
                <div class="flex items-center rounded-lg border border-slate-300 bg-white">
                    <button type="button" data-qty-step="-1" class="h-11 w-11 text-lg" aria-label="Moins">−</button>
                    <input id="quantite" data-qty type="number" inputmode="numeric" min="1" max="999" value="1" class="h-11 w-14 border-x border-slate-300 text-center">
                    <button type="button" data-qty-step="1" class="h-11 w-11 text-lg" aria-label="Plus">+</button>
                </div>
            </div>

            <div class="mt-6 grid gap-3">
                <button type="button" data-add-to-cart class="btn-store w-full" @disabled(! $inStock($selected))>Ajouter au panier</button>
                <button type="button" data-buy-now class="btn-outline w-full" @disabled(! $inStock($selected))>Commander maintenant</button>
                @if ($showWhatsapp)
                    <a data-whatsapp href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn-whatsapp w-full">
                        @include('storefront.partials.whatsapp-icon', ['size' => 20]) Commander sur WhatsApp
                    </a>
                @endif
            </div>

            <ul class="mt-6 space-y-1 text-sm text-slate-600">
                <li>✓ Paiement à la livraison</li>
                <li>✓ Livraison rapide, frais calculés selon votre quartier</li>
            </ul>

            @if ($description !== '')
                <div class="mt-8 border-t border-slate-200 pt-6">
                    <h2 class="mb-2 text-lg font-semibold">Description</h2>
                    <div class="whitespace-pre-line leading-relaxed text-slate-700">{{ $description }}</div>
                </div>
            @endif
        </div>
    </div>

    @if ($related->isNotEmpty())
        <section class="mt-16">
            <h2 class="mb-4 text-2xl font-bold">Vous aimerez aussi</h2>
            <div class="grid grid-cols-2 gap-x-4 gap-y-6 lg:grid-cols-4">
                @foreach ($related as $card)
                    @include('storefront.partials.card')
                @endforeach
            </div>
        </section>
    @endif

    <script type="application/json" id="product-data">{!! json_encode([
        'title' => $product->title,
        'url' => $productUrl,
        'whatsapp' => $showWhatsapp ? $whatsapp : null,
        'variants' => $variants,
        'selected' => $selected['id'],
        'optionCount' => $hasChoices ? $options->count() : 0,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endsection
