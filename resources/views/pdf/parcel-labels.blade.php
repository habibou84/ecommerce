@php($phone = ($p = $store->whatsapp_phone ?? $store->phone) ? \Jibiat\Socle\PhoneNumber::display($p) : null)
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #000; margin: 0; }
        .name { font-weight: bold; }
        .code { font-weight: bold; letter-spacing: 0.5px; }
        @if ($format === 'mini')
            @page { margin: 2mm; }
        @elseif ($format === 'large')
            @page { margin: 6mm; }
        @else
            @page { margin: 13mm 5mm; }
            td.cell { width: 40mm; height: 30mm; padding: 0; vertical-align: middle; }
        @endif
    </style>
</head>
<body>
@if ($format === 'sheet')
    @foreach (array_chunk($labels, 45) as $p => $page)
        <table style="border-collapse: collapse; @if ($p < intdiv(count($labels) - 1, 45)) page-break-after: always; @endif">
            @foreach (array_chunk($page, 5) as $row)
                <tr>
                    @foreach ($row as $label)
                        <td class="cell"><div style="position: relative; width: 36mm; height: 26mm; margin: 2mm">@include('pdf.partials.mini-label', ['label' => $label, 'store' => $store, 'phone' => $phone])</div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endforeach
@else
    @foreach ($labels as $i => $label)
        <div style="position: relative; @if ($format === 'mini') height: 26mm; @endif @if ($i < count($labels) - 1) page-break-after: always; @endif">
            @if ($format === 'mini')
                @include('pdf.partials.mini-label', ['label' => $label, 'store' => $store, 'phone' => $phone])
            @else
                <div class="name" style="font-size: 18pt">{{ $store->name }}</div>
                @if ($phone)<div style="font-size: 11pt">{{ $phone }}</div>@endif
                <div style="text-align: center; margin-top: 6mm"><img src="{{ $label['qr'] }}" style="width: 55mm; height: 55mm"></div>
                <div class="code" style="text-align: center; font-size: 16pt">{{ $label['code'] }}</div>
                <div style="text-align: center; font-size: 8pt">Scanne pour suivre ce colis</div>
                <div style="border-top: 1.5px solid #000; margin-top: 6mm; padding-top: 4mm; font-size: 10pt; line-height: 2.2">
                    Client : ........................................................<br>
                    Quartier : ....................................................<br>
                    Tél : ..............................................................
                </div>
            @endif
        </div>
    @endforeach
@endif
</body>
</html>
