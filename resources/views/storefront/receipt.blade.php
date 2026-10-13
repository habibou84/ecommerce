@php($money = fn ($v) => number_format((int) $v, 0, ',', ' ').' F')
@php($date = $order->created_at->setTimezone($store->timezone))
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Ticket {{ $order->reference() }} · {{ $store->name }}</title>
    <style>
        /* Ticket de caisse : lisible sur téléphone, imprimable sur une imprimante de tickets de 58 ou 80 mm */
        @page { size: 80mm auto; margin: 3mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: #0f172a; font: 14px/1.4 ui-monospace, "DejaVu Sans Mono", Menlo, Consolas, monospace; }
        .ticket { max-width: 80mm; margin: 16px auto; background: #fff; padding: 14px 12px; box-shadow: 0 1px 3px rgb(0 0 0 / 0.12); }
        .center { text-align: center; }
        .store { font-size: 17px; font-weight: 700; }
        .muted { color: #475569; font-size: 12px; }
        hr { border: 0; border-top: 1px dashed #64748b; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .right { text-align: right; white-space: nowrap; }
        .total td { font-size: 17px; font-weight: 700; padding-top: 4px; }
        .due { margin-top: 8px; padding: 6px; border: 1px solid #0f172a; text-align: center; font-weight: 700; }
        .actions { max-width: 80mm; margin: 0 auto 24px; display: flex; gap: 8px; }
        .actions button { flex: 1; padding: 10px; border: 0; border-radius: 8px; background: #047857; color: #fff; font: 600 15px system-ui, sans-serif; }
        @media print {
            body { background: #fff; }
            .ticket { margin: 0; padding: 0; box-shadow: none; max-width: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <main class="ticket">
        <div class="center">
            <div class="store">{{ $store->name }}</div>
            @if ($store->address)<div class="muted">{{ $store->address }}</div>@endif
            @if ($store->phone)<div class="muted">{{ \Jibiat\Socle\PhoneNumber::display($store->phone) }}</div>@endif
        </div>
        <hr>
        <table>
            <tr><td>Ticket {{ $order->reference() }}</td><td class="right">{{ $date->format('d/m/Y H:i') }}</td></tr>
            @if ($order->customer_name)<tr><td colspan="2">Client : {{ $order->customer_name }}</td></tr>@endif
        </table>
        <hr>
        <table>
            @foreach ($order->items as $item)
                <tr><td colspan="2">{{ $item->label() }}</td></tr>
                <tr><td class="muted">&nbsp;&nbsp;{{ $item->quantity }} × {{ $money($item->unit_price) }}</td><td class="right">{{ $money($item->total) }}</td></tr>
            @endforeach
        </table>
        <hr>
        <table>
            @if ($order->discount_total)
                <tr><td>Sous-total</td><td class="right">{{ $money($order->subtotal) }}</td></tr>
                <tr><td>Remise</td><td class="right">− {{ $money($order->discount_total) }}</td></tr>
            @endif
            @if ($order->shipping_fee)
                <tr><td>Livraison</td><td class="right">{{ $money($order->shipping_fee) }}</td></tr>
            @endif
            <tr class="total"><td>TOTAL</td><td class="right">{{ $money($order->total) }}</td></tr>
            @foreach ($order->payments as $payment)
                <tr><td class="muted">{{ $payment->method->label() }}</td><td class="right muted">{{ $money($payment->amount) }}</td></tr>
            @endforeach
        </table>
        @if ($order->amountDue() > 0)
            <div class="due">Reste à payer : {{ $money($order->amountDue()) }}</div>
        @endif
        <hr>
        <div class="center">Merci de votre visite !</div>
        @if ($store->storefront_enabled)<div class="center muted">{{ rtrim(preg_replace('#^https?://#', '', $store->url('/')), '/') }}</div>@endif
    </main>
    <div class="actions">
        <button type="button" onclick="window.print()">Imprimer</button>
    </div>
    @if (request()->boolean('imprimer'))
        <script>window.addEventListener('load', () => window.print())</script>
    @endif
</body>
</html>
