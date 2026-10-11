@extends('layouts.storefront')

@section('no-floating-whatsapp', true)

@section('content')
    <div class="mx-auto max-w-md py-10">
        <h1 class="text-2xl font-bold">Paiement</h1>
        @if ($link->order)
            <p class="mt-1 text-slate-600">Commande {{ $link->order->reference() }}{{ $link->description ? ' · '.$link->description : '' }}</p>
        @endif

        @if ($link->status === 'paid' || ($link->order && $link->order->amountDue() <= 0))
            <div class="mt-6 rounded-xl bg-brand-soft p-6 text-center">
                <p class="text-3xl">✓</p>
                <p class="mt-2 font-semibold">Ce paiement est déjà réglé. Merci !</p>
            </div>
        @elseif (! $link->isOpen())
            <p class="mt-6 rounded-lg bg-amber-50 p-4 text-amber-900">Ce lien de paiement n'est plus valable. Contactez la boutique.</p>
        @elseif ($methods === [])
            <p class="mt-6 rounded-lg bg-amber-50 p-4 text-amber-900">Le paiement en ligne n'est pas disponible pour le moment. Contactez la boutique.</p>
        @else
            <div class="mt-6">
                @include('storefront.partials.pay-order', ['action' => '/payer/'.$link->token, 'methods' => $methods, 'amount' => min($link->amount, $link->order?->amountDue() ?? $link->amount), 'title' => 'Montant à payer'])
            </div>
        @endif
    </div>
@endsection
