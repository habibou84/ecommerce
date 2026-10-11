{{-- Lignes du panier ; $editable : quantités modifiables (page panier) --}}
<ul class="divide-y divide-slate-200" data-cart-lines>
    @foreach ($summary['lines'] as $line)
        <li class="flex gap-3 py-4" data-line="{{ $line['variant_id'] }}">
            <a href="/produits/{{ $line['product_slug'] }}" class="h-20 w-20 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                @if ($line['image'])
                    <img src="{{ $line['image'] }}" alt="" width="80" height="80" loading="lazy" class="h-full w-full object-cover">
                @endif
            </a>
            <div class="min-w-0 flex-1">
                <a href="/produits/{{ $line['product_slug'] }}" class="font-medium hover:underline">{{ $line['title'] }}</a>
                @if ($line['variant_title'])
                    <p class="text-sm text-slate-500">{{ $line['variant_title'] }}</p>
                @endif
                <p class="text-sm text-slate-500">
                    <span data-line-unit>@money($line['unit_price'])</span>
                    <s data-line-compare class="text-slate-400" @if (! $line['compare_at']) hidden @endif>{{ $line['compare_at'] ? \Jibiat\Socle\Money::format($line['compare_at']) : '' }}</s>
                    <span data-line-volume class="text-brand" @if (! $line['volume']) hidden @endif>· prix par quantité</span>
                </p>
                <p data-line-short class="text-sm text-red-700" @if (! $line['short']) hidden @endif>Il n'en reste que {{ $line['max'] }} : ajustez la quantité.</p>
                @if ($editable ?? false)
                    <div class="mt-2 flex items-center gap-3">
                        <div class="flex items-center rounded-lg border border-slate-300 bg-white">
                            <button type="button" data-line-step="-1" class="h-9 w-9" aria-label="Moins">−</button>
                            <span data-line-qty class="w-8 text-center text-sm">{{ $line['quantity'] }}</span>
                            <button type="button" data-line-step="1" class="h-9 w-9" aria-label="Plus">+</button>
                        </div>
                        <button type="button" data-line-remove class="text-sm text-slate-500 underline">Retirer</button>
                    </div>
                @else
                    <p class="text-sm">Quantité : {{ $line['quantity'] }}</p>
                @endif
            </div>
            <p data-line-total class="shrink-0 font-medium">@money($line['total'])</p>
        </li>
    @endforeach
</ul>
