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
                <div class="mt-4 flex items-center justify-between border-t border-slate-200 pt-4 text-lg">
                    <span>Sous-total</span>
                    <span data-subtotal class="font-semibold">@money($summary['subtotal'])</span>
                </div>
                <p class="mt-1 text-sm text-slate-500">Frais de livraison calculés à l'étape suivante, selon votre quartier.</p>
                <a href="/commande" class="btn-store mt-6 w-full">Commander · paiement à la livraison</a>
                <a href="/produits" class="mt-3 block text-center text-sm text-slate-600 underline">Continuer mes achats</a>
            </div>
        @endif
    </div>
@endsection
