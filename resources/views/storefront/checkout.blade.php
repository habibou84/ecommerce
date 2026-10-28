@extends('layouts.storefront')

@section('no-floating-whatsapp', true)

@section('content')
    <div class="mx-auto max-w-5xl py-6">
        <h1 class="mb-6 text-2xl font-bold sm:text-3xl">Finaliser ma commande</h1>

        <form data-checkout novalidate class="grid gap-8 md:grid-cols-[1fr_22rem]">
            <div class="space-y-6">
                <section class="space-y-4">
                    <h2 class="text-lg font-semibold">Vos coordonnées</h2>
                    <div>
                        <label for="name" class="field-label">Nom et prénom</label>
                        <input id="name" name="name" autocomplete="name" required value="{{ $cart->name }}" class="field">
                        <p data-error="name" class="mt-1 text-sm text-red-700" hidden></p>
                    </div>
                    <div>
                        <label for="phone" class="field-label">Téléphone</label>
                        <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="07 00 00 00 00"
                               value="{{ $cart->phone ? \Jibiat\Socle\PhoneNumber::display($cart->phone) : '' }}" class="field">
                        <p class="mt-1 text-xs text-slate-500">La boutique vous appelle pour confirmer la commande.</p>
                        <p data-error="phone" class="mt-1 text-sm text-red-700" hidden></p>
                    </div>
                </section>

                <section class="space-y-4">
                    <h2 class="text-lg font-semibold">Livraison</h2>
                    @if ($pickupPoints->isNotEmpty())
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white p-3 has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                                <input type="radio" name="delivery_method" value="delivery" checked class="accent-[var(--brand)]"> Livraison
                            </label>
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white p-3 has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                                <input type="radio" name="delivery_method" value="pickup" class="accent-[var(--brand)]"> Retrait en boutique
                            </label>
                        </div>
                    @else
                        <input type="hidden" name="delivery_method" value="delivery">
                    @endif

                    <div data-when="delivery" class="space-y-4">
                        <div class="relative">
                            <label for="place" class="field-label">Commune ou quartier</label>
                            <input id="place" data-place-search autocomplete="off" placeholder="Ex. Cocody Angré, Yopougon…" class="field"
                                   role="combobox" aria-expanded="false" aria-controls="place-options" aria-autocomplete="list">
                            <input type="hidden" name="place_id">
                            <ul id="place-options" data-place-options role="listbox" hidden
                                class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white shadow-lg"></ul>
                            <p data-error="place_id" class="mt-1 text-sm text-red-700" hidden></p>
                        </div>
                        <div>
                            <label for="address" class="field-label">Adresse <span class="font-normal text-slate-400">(facultatif)</span></label>
                            <input id="address" name="address" autocomplete="street-address" placeholder="Rue, résidence, immeuble" class="field">
                        </div>
                        <div>
                            <label for="landmark" class="field-label">Repère <span class="font-normal text-slate-400">(facultatif)</span></label>
                            <input id="landmark" name="landmark" placeholder="Ex. en face de la pharmacie" class="field">
                        </div>
                    </div>

                    @if ($pickupPoints->isNotEmpty())
                        <div data-when="pickup" hidden>
                            <label for="pickup_location_id" class="field-label">Point de retrait</label>
                            <select id="pickup_location_id" name="pickup_location_id" class="field">
                                @foreach ($pickupPoints as $point)
                                    <option value="{{ $point->id }}">{{ $point->name }}{{ $point->address ? ' · '.$point->address : '' }}</option>
                                @endforeach
                            </select>
                            <p data-error="pickup_location_id" class="mt-1 text-sm text-red-700" hidden></p>
                        </div>
                    @endif

                    <div>
                        <label for="note" class="field-label">Précisions pour la boutique <span class="font-normal text-slate-400">(facultatif)</span></label>
                        <textarea id="note" name="note" rows="2" class="field"></textarea>
                    </div>
                    @if ($referral)
                        <div>
                            <label for="referral_code" class="field-label">Code de parrainage <span class="font-normal text-slate-400">(facultatif)</span></label>
                            <input id="referral_code" name="referral_code" maxlength="12" class="field uppercase" autocomplete="off" data-referral-code>
                            <p class="mt-1 text-sm text-slate-500">Un ami vous a donné son code ? @money($referral) de réduction sur votre première commande.</p>
                        </div>
                    @endif
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="accepts_marketing" value="1" class="mt-0.5">
                        <span>Recevoir les nouveautés et offres de {{ $store->name }} sur WhatsApp (vous pouvez arrêter à tout moment en répondant STOP).</span>
                    </label>
                    {{-- Champ piège pour les robots, invisible pour les visiteurs --}}
                    <div class="absolute -left-[9999px]" aria-hidden="true">
                        <label for="website">Ne pas remplir</label>
                        <input id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>
                </section>
            </div>

            <aside class="h-fit rounded-xl border border-slate-200 bg-white p-4 md:sticky md:top-4">
                <h2 class="mb-2 text-lg font-semibold">Récapitulatif</h2>
                @include('storefront.partials.cart-lines', ['editable' => false])
                @include('storefront.partials.promo-code')
                <dl class="mt-3 space-y-2 border-t border-slate-200 pt-3 text-sm">
                    <div class="flex justify-between"><dt>Sous-total</dt><dd>@money($summary['subtotal'])</dd></div>
                    @if ($summary['discount']['amount'] ?? 0)
                        <div class="flex justify-between text-brand"><dt>Remise ({{ $summary['discount']['code'] }})</dt><dd>− @money($summary['discount']['amount'])</dd></div>
                    @endif
                    <div class="flex justify-between gap-4"><dt>Livraison</dt><dd data-fee class="text-right text-slate-500">Choisissez votre quartier</dd></div>
                    <p data-eta class="text-xs text-slate-500" hidden></p>
                    <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><dt>Total</dt><dd data-total>@money($summary['total'])</dd></div>
                </dl>
                @include('storefront.partials.free-shipping')
                @if ($payment['mode'] === 'off')
                    <p class="mt-4 rounded-lg bg-brand-soft p-3 text-sm">💵 Paiement à la livraison, en espèces ou Mobile Money.</p>
                @else
                    <fieldset class="mt-4 space-y-3 text-sm" data-payment>
                        <legend class="mb-2 font-semibold">Paiement</legend>
                        @if ($payment['mode'] === 'optional')
                            <label class="flex items-center gap-2"><input type="radio" name="payment" value="cod" checked class="accent-[var(--brand)]"> À la livraison (espèces ou Mobile Money)</label>
                            <label class="flex items-center gap-2"><input type="radio" name="payment" value="online" class="accent-[var(--brand)]"> Maintenant en ligne</label>
                        @elseif ($payment['mode'] === 'deposit')
                            <input type="hidden" name="payment" value="online">
                            <p class="rounded-lg bg-brand-soft p-3">Acompte de {{ $payment['deposit_percent'] }} % à payer maintenant ({{ \Jibiat\Socle\Money::format($payment['deposit']) }} environ), le reste à la livraison.</p>
                        @else
                            <input type="hidden" name="payment" value="online">
                            <p class="rounded-lg bg-brand-soft p-3">Paiement en ligne à la commande.</p>
                        @endif
                        <div data-payment-methods @if ($payment['mode'] === 'optional') hidden @endif>
                            @include('storefront.partials.pay-methods', ['methods' => $payment['methods']])
                        </div>
                    </fieldset>
                @endif
                <p data-error="form" class="mt-3 text-sm text-red-700" hidden></p>
                <button type="submit" class="btn-store mt-4 w-full">Confirmer la commande</button>
            </aside>
        </form>
    </div>

    @push('tracking')
        jmTrack('begin_checkout', {!! json_encode(['value' => $summary['total'], 'currency' => $store->currency, 'items' => collect($summary['lines'])->map(fn ($l) => ['id' => $l['catalog_id'], 'name' => $l['title'], 'price' => $l['unit_price'], 'quantity' => $l['quantity']])->values()], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!});
    @endpush

    <script type="application/json" id="checkout-data">{!! json_encode(['subtotal' => $summary['subtotal'], 'total' => $summary['total']], JSON_HEX_TAG) !!}</script>
@endsection
