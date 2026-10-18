@extends('layouts.page')

@section('title', 'Commande '.$order->reference().' · '.$store->name)

@section('content')
    @php($money = fn ($v) => number_format((int) $v, 0, ',', ' ').' F')
    <main class="mx-auto max-w-lg px-4 py-8">
        <a href="/" class="text-sm text-slate-500">{{ $store->name }}</a>
        <h1 class="mt-1 text-2xl font-bold">Commande {{ $order->reference() }}</h1>
        <p class="text-sm text-slate-500">Passée le {{ $order->created_at->format('d/m/Y') }}</p>

        @if (in_array($order->status->value, ['cancelled', 'returned']))
            <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900">Cette commande est {{ mb_strtolower($order->status->label()) }}.</div>
        @else
            <ol class="mt-6 space-y-3">
                @foreach ($steps as $step)
                    <li class="flex items-center gap-3">
                        <span class="grid h-7 w-7 place-items-center rounded-full text-sm {{ $step['done'] ? 'bg-emerald-700 text-white' : 'bg-slate-200 text-slate-500' }}">{{ $step['done'] ? '✓' : $loop->iteration }}</span>
                        <span class="{{ $step['done'] ? 'font-medium' : 'text-slate-500' }}">{{ $step['label'] }}</span>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($shipment)
            <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                <p><span class="text-slate-500">Livraison :</span> <strong>{{ $shipment->connection?->name ?? ($shipment->courier ? 'livreur '.$shipment->courier->name : 'Entreprise de livraison') }}</strong> · {{ $shipment->status->label() }}</p>
                @if ($shipment->carrier_tracking_code)
                    <p class="mt-1"><span class="text-slate-500">N° de suivi :</span> {{ $shipment->carrier_tracking_code }}</p>
                @endif
                @if ($shipment->delivery_code && ! $shipment->status->isFinal())
                    <p class="mt-1"><span class="text-slate-500">Code à donner au livreur :</span> <strong class="tracking-widest">{{ $shipment->delivery_code }}</strong></p>
                @endif
                @if ($shipment->carrier_tracking_url)
                    <a href="{{ $shipment->carrier_tracking_url }}" target="_blank" rel="noopener" class="mt-2 inline-block font-medium text-emerald-800 underline">Suivre le colis en direct</a>
                @endif
            </div>
        @endif

        <div class="mt-8 rounded-xl border border-slate-200">
            <ul class="divide-y divide-slate-100">
                @foreach ($order->items as $item)
                    <li class="flex justify-between gap-3 px-4 py-3 text-sm">
                        <span>{{ $item->quantity }} × {{ $item->label() }}</span>
                        <span>{{ $money($item->total) }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm">
                @if ($order->discount_total + $order->referral_discount + $order->points_discount)
                    <div class="flex justify-between"><span>Réductions</span><span>− {{ $money($order->discount_total + $order->referral_discount + $order->points_discount) }}</span></div>
                @endif
                <div class="flex justify-between"><span>Livraison</span><span>{{ $order->shipping_fee ? $money($order->shipping_fee) : 'Offerte' }}</span></div>
                <div class="flex justify-between font-semibold"><span>Total</span><span>{{ $money($order->total) }}</span></div>
                @if ($order->amountDue() > 0 && ! in_array($order->status->value, ['cancelled', 'returned']))
                    <div class="flex justify-between text-emerald-800"><span>Reste à payer</span><span>{{ $money($order->amountDue()) }}</span></div>
                @endif
            </div>
        </div>

        @if ($rewards)
            <div class="mt-6 space-y-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm">
                @if ($loyalty['enabled'])
                    <p><strong>{{ $rewards['points'] }} point(s) de fidélité</strong> chez {{ $store->name }}, soit {{ $money($rewards['value']) }} de réduction à utiliser sur une prochaine commande (à demander à la boutique).</p>
                @endif
                @if ($rewards['referral_code'])
                    @php($referralText = 'Je te recommande '.$store->name.' ! Avec mon code '.$rewards['referral_code'].', tu as '.\Jibiat\Socle\Money::format($loyalty['referral_discount']).' de réduction sur ta première commande : '.$store->url('/?parrain='.$rewards['referral_code']))
                    <p>Parrainez vos proches avec votre code <strong class="font-mono">{{ $rewards['referral_code'] }}</strong> : ils ont {{ $money($loyalty['referral_discount']) }} de réduction sur leur première commande et vous gagnez {{ $loyalty['referral_points'] }} points quand elle est livrée.</p>
                    <a href="https://wa.me/?text={{ rawurlencode($referralText) }}" class="inline-block font-medium text-emerald-800 underline">Partager mon code sur WhatsApp</a>
                @endif
            </div>
        @endif

        @if ($store->whatsapp_phone)
            <a href="https://wa.me/{{ ltrim($store->whatsapp_phone, '+') }}?text={{ rawurlencode('Bonjour, au sujet de ma commande '.$order->reference()) }}" class="btn-brand mt-6 w-full py-3">Contacter la boutique sur WhatsApp</a>
        @endif
    </main>
@endsection
