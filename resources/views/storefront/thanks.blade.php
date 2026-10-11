@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-lg py-10 text-center">
        <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-brand text-3xl text-brand-contrast">✓</div>
        <h1 class="mt-4 text-2xl font-bold">Merci {{ \Illuminate\Support\Str::of($order->customer_name)->before(' ') }} !</h1>
        <p class="mt-2 text-slate-600">Votre commande <strong>{{ $order->reference() }}</strong> est bien reçue.
            @if ($order->status->value === 'pending_confirmation')
                Nous vous appelons très vite pour la confirmer.
            @endif
        </p>

        <div class="mt-8 rounded-xl border border-slate-200 bg-white text-left">
            <ul class="divide-y divide-slate-100">
                @foreach ($order->items as $item)
                    <li class="flex justify-between gap-3 px-4 py-3 text-sm">
                        <span>{{ $item->quantity }} × {{ $item->label() }}</span>
                        <span>@money($item->total)</span>
                    </li>
                @endforeach
            </ul>
            <dl class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm">
                <div class="flex justify-between"><dt>Livraison</dt><dd>{{ $order->shipping_fee ? \Jibiat\Socle\Money::format($order->shipping_fee) : ($order->delivery_method === 'pickup' ? 'Retrait' : ($order->internal_note ? 'À confirmer' : 'Offerte')) }}</dd></div>
                @if ($order->amount_paid)
                    <div class="flex justify-between text-emerald-800"><dt>Déjà payé</dt><dd>@money($order->amount_paid)</dd></div>
                @endif
                <div class="flex justify-between font-semibold"><dt>{{ $order->amount_paid ? 'Reste à payer' : 'Total à payer à la livraison' }}</dt><dd>@money($order->amountDue())</dd></div>
            </dl>
        </div>

        @if ($payment['mode'] !== 'off' && $order->amountDue() > 0 && ! in_array($order->status->value, ['cancelled', 'returned']))
            <div class="mt-6">
                @if ($paymentUnavailable)
                    <p class="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Le paiement en ligne n'a pas pu être ouvert. Réessayez, ou payez à la livraison.</p>
                @endif
                @include('storefront.partials.pay-order', [
                    'action' => '/commande/'.$order->tracking_token.'/paiement',
                    'methods' => $payment['methods'],
                    'amount' => $payment['mode'] === 'deposit' && $order->amount_paid === 0 ? min($order->amountDue(), \App\Services\Storefront\OnlinePayment::deposit($order->total, $payment['deposit_percent'])) : $order->amountDue(),
                    'title' => $payment['mode'] === 'deposit' && $order->amount_paid === 0 ? 'Acompte à payer' : 'Payer maintenant',
                ])
            </div>
        @endif

        <div class="mt-6 grid gap-3">
            <a href="/suivi/{{ $order->tracking_token }}" class="btn-outline w-full">Suivre ma commande</a>
            @if ($whatsapp)
                <a href="{{ $whatsapp }}?text={{ rawurlencode($whatsappText) }}" target="_blank" rel="noopener" class="btn-whatsapp w-full">
                    @include('storefront.partials.whatsapp-icon', ['size' => 20]) Écrire à la boutique
                </a>
            @endif
            <a href="/" class="text-sm text-slate-600 underline">Retour à la boutique</a>
        </div>
    </div>
@endsection
