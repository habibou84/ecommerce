@extends('layouts.page')

@section('title', 'Colis '.$order->reference().' · '.$store->name)

@section('content')
    <main class="mx-auto max-w-lg px-4 py-8">
        <a href="/" class="text-sm text-slate-500">{{ $store->name }}</a>
        <h1 class="mt-1 text-2xl font-bold">Colis de la commande {{ $order->reference() }}</h1>
        <p class="text-sm text-slate-500">Envoyé par {{ $store->name }}</p>

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
            <p class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm"><span class="text-slate-500">Livraison :</span> <strong>{{ $shipment->connection?->name ?? ($shipment->courier ? 'livreur '.$shipment->courier->name : 'Entreprise de livraison') }}</strong> · {{ $shipment->status->label() }}</p>
        @endif

        <p class="mt-6 text-sm text-slate-600">C'est ton colis ? Ouvre le lien de suivi reçu par message de {{ $store->name }} : tu y trouves le détail de ta commande et le code à donner au livreur.</p>

        @if ($store->whatsapp_phone)
            <a href="https://wa.me/{{ ltrim($store->whatsapp_phone, '+') }}?text={{ rawurlencode('Bonjour, au sujet du colis de la commande '.$order->reference()) }}" class="btn-brand mt-6 w-full py-3">Contacter la boutique sur WhatsApp</a>
        @endif
    </main>
@endsection
