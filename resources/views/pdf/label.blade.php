@php($money = fn ($v) => number_format((int) $v, 0, ',', ' ').' F')
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 14px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #000; }
        .big { font-size: 22px; font-weight: bold; }
        .box { border: 2px solid #000; padding: 6px; margin-top: 8px; }
        .cod { font-size: 18px; font-weight: bold; text-align: center; }
        .small { font-size: 9px; }
    </style>
</head>
<body>
@foreach ($labels as $i => $label)
@php($order = $label['order'])
@php($qr = $label['qr'])
<div @if ($i < count($labels) - 1) style="page-break-after: always" @endif>
    <table style="width: 100%">
        <tr>
            <td>
                <div class="small">{{ $order->store->name }}</div>
                <div class="big">{{ $order->reference() }}</div>
                <div class="small">{{ $order->created_at->format('d/m/Y') }}</div>
            </td>
            <td style="text-align: right"><img src="{{ $qr }}" style="width: 80px; height: 80px"></td>
        </tr>
    </table>

    <div class="box">
        <strong style="font-size: 14px">{{ $order->shipping_name ?? $order->customer_name }}</strong><br>
        <span style="font-size: 14px">{{ \Jibiat\Socle\PhoneNumber::display($order->shipping_phone ?? $order->customer_phone) }}</span>
        @if ($order->shipping_phone2) / {{ \Jibiat\Socle\PhoneNumber::display($order->shipping_phone2) }}@endif
        <br><br>
        <strong>{{ $order->delivery_method === 'pickup' ? 'Retrait en boutique' : $order->shipping_place_label }}</strong><br>
        {{ $order->shipping_address }}<br>
        @if ($order->shipping_landmark)Repère : {{ $order->shipping_landmark }}@endif
    </div>

    <div class="box cod">
        @if ($order->amountDue() > 0)
            À ENCAISSER : {{ $money($order->amountDue()) }}
        @else
            DÉJÀ PAYÉ
        @endif
    </div>

    <div class="small" style="margin-top: 8px">
        @foreach ($order->items as $item)
            {{ $item->quantity }} × {{ $item->label() }}<br>
        @endforeach
    </div>
</div>
@endforeach
</body>
</html>
