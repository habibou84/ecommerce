@php($money = fn ($v) => number_format((int) $v, 0, ',', ' ').' F CFA')
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
        .items td { border-bottom: 1px solid #e2e8f0; padding: 8px 4px; }
        .right { text-align: right; }
        .total td { font-size: 14px; font-weight: bold; border-top: 1px solid #0f172a; padding-top: 6px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                <h1>Jibiat Marchand</h1>
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
                <td>Abonnement {{ $invoice->plan->name }} ({{ $invoice->billing_cycle === 'yearly' ? 'annuel' : 'mensuel' }}){{ $invoice->status === 'offered' ? ' — offert' : '' }}</td>
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
