@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-2xl py-6">
        <h1 class="mb-4 text-2xl font-bold sm:text-3xl">Votre panier</h1>

        @if ($summary['removed'])
            <p class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Retiré car plus en vente : {{ implode(', ', $summary['removed']) }}.</p>
        @endif

        <div data-cart-empty @if ($summary['lines']) hidden @endif class="py-12 text-center">
            <p class="text-slate-500">Votre panier est vide.</p>
            <a href="/produits" class="btn-store mt-6">Voir la boutique</a>
        </div>

        @if ($summary['lines'])
            <div data-cart-full>
                @include('storefront.partials.cart-lines', ['editable' => true])
                @include('storefront.partials.promo-code')
                <dl class="mt-4 space-y-1 border-t border-slate-200 pt-4">
                    <div class="flex justify-between"><dt>Sous-total</dt><dd data-subtotal>@money($summary['subtotal'])</dd></div>
                    @php($amount = $summary['discount']['amount'] ?? 0)
                    <div data-discount-row class="flex justify-between text-brand" @if (! $amount) hidden @endif><dt>Remise</dt><dd data-discount>− @money($amount)</dd></div>
                    <div class="flex justify-between text-lg"><dt>Total</dt><dd data-cart-total class="font-semibold">@money($summary['total'])</dd></div>
                </dl>
                @include('storefront.partials.free-shipping')
                <p class="mt-2 text-sm text-slate-500">Frais de livraison calculés à l'étape suivante, selon votre quartier.</p>
                <a href="/commande" class="btn-store mt-6 w-full">Commander · paiement à la livraison</a>
                <a href="/produits" class="mt-3 block text-center text-sm text-slate-600 underline">Continuer mes achats</a>
            </div>
        @endif
    </div>
@endsection
