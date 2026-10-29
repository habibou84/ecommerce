@extends('layouts.storefront')

@section('no-floating-whatsapp', true)

@section('content')
    <div class="mx-auto max-w-xl py-6" data-live>
        @if (! $live)
            <div class="py-16 text-center">
                <p class="text-4xl" aria-hidden="true">📺</p>
                <h1 class="mt-3 text-2xl font-bold">Pas de live en ce moment</h1>
                <p class="mt-2 text-slate-600">Revenez pendant le prochain live de {{ $store->name }} : les articles et leurs codes s'afficheront ici.</p>
                <a href="/" class="btn-outline mt-6 inline-flex">Voir la boutique</a>
            </div>
        @else
            {{-- En direct : bandeau, lien pour revenir regarder le live --}}
            <section class="overflow-hidden rounded-3xl bg-gradient-to-br from-orange-800 via-orange-600 to-orange-400 p-5 text-white">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide"><span class="h-2 w-2 animate-pulse rounded-full bg-white"></span>EN DIRECT</span>
                <h1 class="mt-2 text-2xl font-extrabold">{{ $live['title'] }}</h1>
                <p class="mt-1 text-sm text-white/90">Touchez « Je prends », puis « Réserver » : une seule commande pour tout.</p>
                @if ($live['stream_url'])
                    <a href="{{ $live['stream_url'] }}" target="_blank" rel="noopener nofollow" class="mt-4 flex items-center justify-center gap-2 rounded-2xl bg-white py-3 font-extrabold text-orange-700">▶ Regarder le live</a>
                @endif
            </section>

            <script type="application/json" id="live-data">@json($live['items'], JSON_HEX_TAG)</script>
            {{-- Grille de deux ; l'article montré en ce moment passe en premier, en grand --}}
            <ul class="mt-5 grid grid-cols-2 gap-3 pb-28" data-live-items data-featured="{{ $live['featured_item_id'] }}">
                @foreach ($live['items'] as $item)
                    @php($featured = $item['id'] === $live['featured_item_id'])
                    <li class="flex min-w-0 flex-col overflow-hidden rounded-2xl border bg-white {{ $featured ? 'col-span-2 border-2 border-orange-600 shadow-lg' : 'border-slate-200' }}" style="{{ $featured ? 'order:-1' : '' }}" data-live-item="{{ $item['id'] }}">
                        <p class="px-3 pt-3 text-xs font-extrabold tracking-wide text-orange-700 uppercase" data-featured-label @if (! $featured) hidden @endif>● Présenté en ce moment</p>
                        <div class="relative {{ $featured ? 'mx-3 mt-2 aspect-[4/3] overflow-hidden rounded-xl' : 'aspect-square' }} bg-slate-100" data-live-photo>
                            @if ($item['image'])
                                <img src="{{ $item['image'] }}" alt="" class="h-full w-full object-cover" loading="lazy">
                            @else
                                <span class="grid h-full w-full place-items-center text-4xl font-black text-slate-300">{{ $item['code'] }}</span>
                            @endif
                            <span class="absolute top-2 left-2 rounded-lg bg-red-600 px-2 py-0.5 text-sm font-black text-white">{{ $item['code'] }}</span>
                        </div>
                        <div class="flex flex-1 flex-col p-3">
                            <p class="font-semibold leading-snug [overflow-wrap:anywhere]">{{ $item['title'] }}</p>
                            <p class="mt-0.5 text-lg font-extrabold whitespace-nowrap">{{ \Jibiat\Socle\Money::format($item['price']) }}</p>
                            <p class="mt-0.5 text-sm {{ $item['remaining'] === 1 ? 'font-bold text-orange-700' : 'text-slate-500' }}" data-remaining>{{ $item['remaining'] === 1 ? '🔥 Dernière pièce !' : ($item['remaining'] > 0 ? 'Il en reste '.$item['remaining'] : 'Épuisé · liste d\'attente') }}</p>
                            <div class="mt-auto pt-3" data-pick="{{ $item['id'] }}">
                                <button type="button" class="btn-store w-full px-3" data-take>{{ $item['remaining'] > 0 ? 'Je prends' : 'M\'inscrire en attente' }}</button>
                                <div class="flex items-center justify-center gap-2" data-stepper hidden>
                                    <button type="button" class="grid h-10 w-10 place-items-center rounded-full border border-slate-300 text-xl font-bold" data-minus aria-label="Une pièce de moins">−</button>
                                    <span class="w-7 text-center text-lg font-bold" data-qty aria-live="polite">1</span>
                                    <button type="button" class="grid h-10 w-10 place-items-center rounded-full border border-slate-300 text-xl font-bold disabled:opacity-30" data-plus aria-label="Une pièce de plus">+</button>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur" data-live-bar hidden>
                <div class="mx-auto flex max-w-xl items-center gap-3">
                    <p class="min-w-0 flex-1 leading-tight"><span class="block text-sm text-slate-600" data-bar-count></span><strong class="text-lg" data-bar-total></strong></p>
                    <button type="button" class="btn-store px-6" data-live-open>Réserver</button>
                </div>
            </div>

            <dialog data-live-dialog class="m-auto w-[min(28rem,92vw)] rounded-2xl p-0 backdrop:bg-black/50">
                <form method="dialog" data-live-form class="space-y-4 p-5">
                    <h2 class="text-lg font-bold" data-live-title>Réserver</h2>
                    <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm" data-live-summary></ul>
                    <div>
                        <label for="live-name" class="field-label">Votre nom</label>
                        <input id="live-name" name="name" class="field" autocomplete="name" required maxlength="120">
                        <p data-error="name" class="mt-1 text-sm text-red-700" hidden></p>
                    </div>
                    <div>
                        <label for="live-phone" class="field-label">Votre numéro (WhatsApp)</label>
                        <input id="live-phone" name="phone" class="field" type="tel" inputmode="tel" autocomplete="tel" required placeholder="07 00 00 00 00">
                        <p data-error="phone" class="mt-1 text-sm text-red-700" hidden></p>
                    </div>
                    <div class="relative">
                        <label for="live-place" class="field-label">Commune ou quartier de livraison</label>
                        <input id="live-place" data-place-search autocomplete="off" placeholder="Ex. Cocody Angré, Yopougon…" class="field"
                               role="combobox" aria-expanded="false" aria-controls="live-place-options" aria-autocomplete="list">
                        <input type="hidden" name="place_id">
                        <ul id="live-place-options" data-place-options role="listbox" hidden
                            class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-slate-200 bg-white shadow-lg"></ul>
                        <p data-error="place_id" class="mt-1 text-sm text-red-700" hidden></p>
                    </div>
                    <div>
                        <label for="live-address" class="field-label">Repère <span class="font-normal text-slate-400">(facultatif)</span></label>
                        <input id="live-address" name="address" class="field" maxlength="255" placeholder="Ex. face à la pharmacie">
                    </div>
                    <p data-error="form" class="text-sm text-red-700" hidden></p>
                    <div class="flex gap-2">
                        <button type="button" class="btn-outline flex-1" data-live-close>Annuler</button>
                        <button type="submit" class="btn-store flex-1" data-live-submit>Réserver</button>
                    </div>
                </form>
            </dialog>
        @endif
    </div>
@endsection
