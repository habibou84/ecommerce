@php($banner = $theme['layout']['header'] === 'banner')
<header class="{{ $banner ? 'bg-brand text-brand-contrast' : 'border-b border-slate-200 bg-white' }}">
    <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3 {{ $banner ? '' : 'sm:py-4' }}">
        <a href="/produits" class="grid h-10 w-10 place-items-center rounded-full {{ $banner ? 'hover:bg-white/10' : 'hover:bg-slate-100' }} {{ $banner ? 'order-3' : '' }}" aria-label="Rechercher">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        </a>
        <a href="/" class="flex min-w-0 flex-1 items-center gap-2 {{ $banner ? 'order-1' : 'justify-center' }}">
            @if ($logo)
                <img src="{{ $logo }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover" width="40" height="40">
            @endif
            <span class="truncate font-heading text-xl font-bold">{{ $store->name }}</span>
        </a>
        <a href="/panier" class="relative grid h-10 w-10 place-items-center rounded-full {{ $banner ? 'order-4 hover:bg-white/10' : 'hover:bg-slate-100' }}" aria-label="Panier">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 7h12l-1 13H7L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>
            <span data-cart-count class="absolute -top-0.5 -right-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-slate-900 px-1 text-xs text-white" @if (! $cartCount) hidden @endif>{{ $cartCount }}</span>
        </a>
    </div>
    @if ($menus['main'])
        <nav aria-label="Menu principal" class="mx-auto max-w-6xl overflow-x-auto px-4 pb-2">
            <ul class="flex gap-1 whitespace-nowrap text-sm {{ $banner ? '' : 'justify-start sm:justify-center' }}">
                @foreach ($menus['main'] as $item)
                    <li><a href="{{ $item['url'] }}" class="block rounded-full px-3 py-1.5 {{ $banner ? 'hover:bg-white/10' : 'text-slate-700 hover:bg-slate-100' }}">{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
        </nav>
    @endif
</header>
