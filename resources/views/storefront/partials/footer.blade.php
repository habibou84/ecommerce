<footer class="border-t border-slate-200 bg-white">
    <div class="mx-auto grid max-w-6xl gap-6 px-4 py-10 text-sm text-slate-600 sm:grid-cols-3">
        <div>
            <p class="font-heading text-base font-semibold text-slate-900">{{ $store->name }}</p>
            @if ($theme['settings']['footer_text'])
                <p class="mt-2 whitespace-pre-line">{{ $theme['settings']['footer_text'] }}</p>
            @elseif ($store->tagline)
                <p class="mt-2">{{ $store->tagline }}</p>
            @endif
        </div>
        @if ($menus['footer'])
            <ul class="space-y-2">
                @foreach ($menus['footer'] as $item)
                    <li><a href="{{ $item['url'] }}" class="hover:text-slate-900 hover:underline">{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
        @endif
        <div class="space-y-2">
            @if ($store->address)
                <p>{{ $store->address }}</p>
            @endif
            @if ($store->phone)
                <p><a href="tel:{{ $store->phone }}" class="hover:underline">{{ \Jibiat\Socle\PhoneNumber::display($store->phone) }}</a></p>
            @endif
            @if ($whatsapp)
                <p><a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:underline">@include('storefront.partials.whatsapp-icon', ['size' => 16]) WhatsApp</a></p>
            @endif
            <p>Paiement à la livraison</p>
        </div>
    </div>
    @php($platform = \App\Support\Tenancy::platformUrl())
    <p class="pb-6 text-center text-xs text-slate-400">© {{ date('Y') }} {{ $store->name }} · Propulsé par <a href="{{ $platform }}" class="font-semibold text-slate-500 underline">Jibiat Marchand</a> · <a href="{{ $platform }}" class="hover:underline">{{ parse_url($platform, PHP_URL_HOST) }}</a></p>
</footer>
