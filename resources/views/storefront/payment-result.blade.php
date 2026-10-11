@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-md py-10 text-center">
        @if ($payment->status === 'succeeded')
            <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-brand text-3xl text-brand-contrast">✓</div>
            <h1 class="mt-4 text-2xl font-bold">Paiement reçu</h1>
            <p class="mt-2 text-slate-600">@money($payment->amount) payés pour la commande {{ $order->reference() }}.
                @if ($order->amountDue() > 0) Reste à payer à la livraison : <strong>@money($order->amountDue())</strong>. @endif
            </p>
        @elseif ($payment->status === 'pending' && ! $cancelled)
            <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-brand-soft border-t-[var(--brand)]"></div>
            <h1 class="mt-4 text-2xl font-bold">Paiement en cours de vérification</h1>
            <p class="mt-2 text-slate-600">Si vous avez validé le paiement, il sera confirmé dans quelques instants.</p>
            <meta http-equiv="refresh" content="5">
        @else
            <h1 class="text-2xl font-bold">Paiement non abouti</h1>
            <p class="mt-2 text-slate-600">{{ $cancelled ? 'Vous avez annulé le paiement.' : ($payment->failure_reason ?? 'Le paiement a échoué.') }} Votre commande est bien enregistrée.</p>
        @endif

        <div class="mt-8 grid gap-3">
            @if ($payment->status !== 'succeeded' && $order->amountDue() > 0 && $payment->payment_link_id)
                <a href="/payer/{{ $payment->link?->token }}" class="btn-store w-full">Réessayer le paiement</a>
            @elseif ($payment->status !== 'succeeded' && $order->amountDue() > 0)
                <a href="/commande/merci/{{ $order->tracking_token }}" class="btn-store w-full">Réessayer le paiement</a>
            @endif
            <a href="/suivi/{{ $order->tracking_token }}" class="btn-outline w-full">Suivre ma commande</a>
        </div>
    </div>
@endsection
