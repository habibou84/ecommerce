{{-- Carte produit des grilles ; $card vient de StorefrontCatalog::cards() --}}
<a href="/produits/{{ $card['slug'] }}" class="product-card group block">
    <div class="relative aspect-square overflow-hidden bg-slate-100">
        @if ($card['image'])
            <img src="{{ $card['image']['thumb'] }}" srcset="{{ $card['image']['thumb'] }} 400w, {{ $card['image']['medium'] }} 800w"
                 sizes="(min-width: 1024px) 25vw, 50vw" alt="{{ $card['image_alt'] }}" width="400" height="400"
                 loading="{{ ($eager ?? false) ? 'eager' : 'lazy' }}" decoding="async" class="h-full w-full object-cover transition group-hover:scale-105">
        @else
            @include('storefront.partials.no-photo')
        @endif
        @if (! $card['in_stock'])
            <span class="absolute top-2 left-2 rounded bg-white/90 px-2 py-0.5 text-xs font-medium text-slate-700">Épuisé</span>
        @elseif ($card['compare_at'])
            <span class="absolute top-2 left-2 rounded bg-brand px-2 py-0.5 text-xs font-medium text-brand-contrast">{{ ($card['flash'] ?? false) ? 'Vente flash' : 'Promo' }}</span>
        @endif
    </div>
    <div class="px-1 pt-2 pb-1">
        <h3 class="line-clamp-2 text-sm font-medium text-slate-900" style="font-family: inherit">{{ $card['title'] }}</h3>
        <p class="mt-1 text-sm">
            @if ($card['price_min'] !== $card['price_max'])<span class="text-slate-500">dès</span> @endif
            <span class="font-semibold">@money($card['price_min'])</span>
            @if ($card['compare_at'])
                <s class="ml-1 text-xs text-slate-400">@money($card['compare_at'])</s>
            @endif
        </p>
        @if ($card['volume'] ?? false)
            <p class="text-xs text-brand">Prix dégressif par quantité</p>
        @endif
    </div>
</a>
