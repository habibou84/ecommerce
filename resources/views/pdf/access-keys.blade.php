@php($premium = $batch->isPremium())
@php($offer = match (true) {
    $batch->isFounder() => 'Offre '.$batch->plan->name.' à vie',
    $batch->isPartner() => 'Offre '.$batch->plan->name.' '.$batch->months.' mois, puis −'.round($batch->discount_rate * 100).' %',
    default => 'Offre '.$batch->plan->name.' pendant '.$batch->months.' mois',
})
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 12mm 19mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; margin: 0; }
        table.sheet { border-collapse: collapse; }
        td.cell { width: 85.6mm; height: 54mm; padding: 0; border: 0.2mm dashed #94a3b8; vertical-align: top; }
        .card { width: 85.6mm; height: 54mm; position: relative; overflow: hidden; }
        .premium { background: #0b0f19; color: #fff; }
        .timed { background: #fff; color: #0f172a; }
        .qr { position: absolute; left: 4mm; top: 9mm; width: 30mm; height: 30mm; background: #fff; padding: 1.2mm; }
        .qr img { width: 30mm; height: 30mm; }
        .text { position: absolute; left: 38.5mm; top: 4mm; right: 3mm; }
        .brand { font-size: 7.5pt; font-weight: bold; letter-spacing: 0.3mm; }
        .title { font-size: 10.5pt; font-weight: bold; margin-top: 2.5mm; line-height: 1.15; }
        .gold { color: #fbbf24; }
        .green { color: #047857; }
        .number { font-size: 15pt; font-weight: bold; margin-top: 1mm; }
        .offer { font-size: 7.5pt; margin-top: 1.5mm; }
        .code { font-family: DejaVu Sans Mono, monospace; font-size: 11pt; font-weight: bold; margin-top: 2mm; letter-spacing: 0.4mm; }
        .help { position: absolute; left: 4mm; right: 3mm; bottom: 2.5mm; font-size: 6pt; }
        .muted { color: #64748b; }
        .light { color: #fde68a; }
        .offered { font-size: 6.5pt; margin-top: 1mm; color: #e2e8f0; }
    </style>
</head>
<body>
@foreach ($cards->chunk(10) as $page => $chunk)
<table class="sheet" @if (! $loop->last) style="page-break-after: always" @endif>
    @foreach ($chunk->values()->chunk(2) as $row)
    <tr>
        @foreach ($row as $card)
        <td class="cell">
            <div class="card {{ $premium ? 'premium' : 'timed' }}">
                <div class="qr"><img src="{{ $card['qr'] }}" alt=""></div>
                <div class="text">
                    <div class="brand {{ $premium ? 'light' : 'green' }}">JIBIAT MARCHAND</div>
                    @if ($premium)
                        <div class="title gold">MEMBRE<br>PREMIUM</div>
                        <div class="number gold">n° {{ $card['number'] }}</div>
                        @if ($batch->isPartner() && $batch->distributor_name)
                            <div class="offered">Offerte par {{ $batch->distributor_name }}</div>
                        @endif
                    @else
                        <div class="title green">CLÉ D'ACCÈS</div>
                    @endif
                    <div class="offer">{{ $offer }}</div>
                    <div class="code">{{ $card['code'] }}</div>
                </div>
                <div class="help {{ $premium ? 'light' : 'muted' }}">Scannez le QR code, ou allez sur {{ $domain }}/cle et saisissez le code. Une seule boutique par carte. Assistant IA à la recharge.</div>
            </div>
        </td>
        @endforeach
        @if ($row->count() === 1)<td class="cell"></td>@endif
    </tr>
    @endforeach
</table>
@endforeach
</body>
</html>
