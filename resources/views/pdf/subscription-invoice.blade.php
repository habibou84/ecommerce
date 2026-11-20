@php($money = fn ($v) => number_format((int) $v, 0, ',', ' ').' F CFA')
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111a15; }
        h1 { font-size: 20px; margin: 0; }
        /* Facture de Jibiat Marchand : filet et nom au vert de la marque (charte des couleurs) */
        body { border-top: 4px solid #012e1b; padding-top: 10px; }
        .brand { color: #017c40; }
        .muted { color: #5b6a62; }
        table { width: 100%; border-collapse: collapse; }
        .items th { text-align: left; border-bottom: 1px solid #c7cfc6; padding: 6px 4px; font-size: 10px; color: #3d4a44; text-transform: uppercase; }
        .items td { border-bottom: 1px solid #e0e5df; padding: 8px 4px; }
        .right { text-align: right; }
        .total td { font-size: 14px; font-weight: bold; border-top: 1px solid #111a15; padding-top: 6px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                <h1 class="brand">Jibiat Marchand</h1>
                <div class="muted">{{ config('platform.domain') }}</div>
            </td>
            <td class="right">
                <h1>Facture</h1>
                <div>N° {{ $invoice->number }}</div>
                <div class="muted">{{ ($invoice->paid_at ?? $invoice->created_at)->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <p style="margin-top: 24px">
        <strong>Espace marchand</strong><br>
        {{ $invoice->store->name }} ({{ $invoice->store->slug }}.{{ config('platform.domain') }})<br>
        {{ $invoice->store->owner?->name }} · {{ \Jibiat\Socle\PhoneNumber::display($invoice->store->owner?->phone) }}
    </p>

    <table class="items" style="margin-top: 16px">
        <thead><tr><th>Désignation</th><th>Période</th><th class="right">Montant</th></tr></thead>
        <tbody>
            <tr>
                <td>@if (in_array($invoice->kind, \App\Services\Billing\Credits::KINDS, true)){{ \App\Services\Billing\Credits::label($invoice) }}{{ $invoice->status === 'offered' ? ' — offert' : '' }} @else Abonnement {{ $invoice->plan?->name }} ({{ ['yearly' => 'annuel', 'weekly' => 'à la semaine'][$invoice->billing_cycle] ?? 'mensuel' }}){{ $invoice->status === 'offered' ? ' — offert' : '' }}@endif</td>
                <td>{{ $invoice->period_start?->format('d/m/Y') }} → {{ $invoice->period_end?->format('d/m/Y') }}</td>
                <td class="right">{{ $money($invoice->amount) }}</td>
            </tr>
        </tbody>
    </table>
    <table style="margin-top: 8px">
        <tr class="total"><td>Total payé</td><td class="right">{{ $money($invoice->amount) }}</td></tr>
    </table>
    @if ($invoice->method)
        <p class="muted" style="margin-top: 16px">Payé par {{ \App\Services\Payments\PaymentDesk::METHODS[$invoice->method] ?? $invoice->method }} le {{ $invoice->paid_at?->format('d/m/Y à H:i') }}.</p>
    @endif
</body>
</html>
