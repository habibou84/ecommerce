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
                <div class="flex justify-between font-semibold"><dt>Total à payer à la livraison</dt><dd>@money($order->total)</dd></div>
            </dl>
        </div>

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
