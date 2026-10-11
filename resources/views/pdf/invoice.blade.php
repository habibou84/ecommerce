@php($money = fn ($v) => number_format((int) $v, 0, ',', ' ').' F')
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
        h1 { font-size: 20px; margin: 0; }
        .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; }
        .items th { text-align: left; border-bottom: 1px solid #cbd5e1; padding: 6px 4px; font-size: 10px; color: #475569; text-transform: uppercase; }
        .items td { border-bottom: 1px solid #e2e8f0; padding: 6px 4px; }
        .right { text-align: right; }
        .totals td { padding: 3px 4px; }
        .total td { font-size: 14px; font-weight: bold; border-top: 1px solid #0f172a; padding-top: 6px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                <h1>{{ $order->store->name }}</h1>
                <div class="muted">{{ $order->store->tagline }}</div>
                <div class="muted">{{ $order->store->address }}</div>
                <div class="muted">{{ \Jibiat\Socle\PhoneNumber::display($order->store->phone) }}</div>
            </td>
            <td class="right">
                <h1>Facture</h1>
                <div>Commande {{ $order->reference() }}</div>
                <div class="muted">{{ $order->created_at->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <table style="margin-top: 24px">
        <tr>
            <td>
                <strong>Client</strong><br>
                {{ $order->customer_name }}<br>
                {{ \Jibiat\Socle\PhoneNumber::display($order->customer_phone) }}
            </td>
            <td>
                <strong>{{ $order->delivery_method === 'pickup' ? 'Retrait en boutique' : 'Livraison' }}</strong><br>
                {{ $order->shipping_place_label }}<br>
                {{ $order->shipping_address }}<br>
                <span class="muted">{{ $order->shipping_landmark }}</span>
            </td>
        </tr>
    </table>

    <table class="items" style="margin-top: 24px">
        <thead><tr><th>Article</th><th class="right">Qté</th><th class="right">Prix unitaire</th><th class="right">Total</th></tr></thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $item->label() }}@if ($item->sku) <span class="muted">({{ $item->sku }})</span>@endif</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ $money($item->unit_price) }}</td>
                    <td class="right">{{ $money($item->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 12px; width: 45%; margin-left: 55%">
        <tr><td>Sous-total</td><td class="right">{{ $money($order->subtotal) }}</td></tr>
        @if ($order->discount_total)<tr><td>Remise{{ $order->discount_code ? ' ('.$order->discount_code.')' : '' }}</td><td class="right">− {{ $money($order->discount_total) }}</td></tr>@endif
        <tr><td>Livraison</td><td class="right">{{ $order->shipping_fee ? $money($order->shipping_fee) : 'Offerte' }}</td></tr>
        <tr class="total"><td>Total</td><td class="right">{{ $money($order->total) }}</td></tr>
        @if ($order->amount_paid)
            <tr><td>Déjà payé</td><td class="right">{{ $money($order->amount_paid) }}</td></tr>
            <tr><td><strong>Reste à payer</strong></td><td class="right"><strong>{{ $money($order->amountDue()) }}</strong></td></tr>
        @endif
    </table>

    <p class="muted" style="margin-top: 40px">Merci pour votre achat ! Suivi de la commande : {{ $order->trackingUrl() }}</p>
</body>
</html>
