{{-- Mini étiquette 40 × 30 (zone utile 36 × 26 mm) : QR de 22 mm, numéro dessous, nom et code à droite --}}
@php($parts = explode('-', $label['code']))
<img src="{{ $label['qr'] }}" style="position: absolute; left: 0; top: 0; width: 22mm; height: 22mm">
@if ($phone)
    <div style="position: absolute; left: 0; top: 22.4mm; width: 36mm; font-size: 6pt">{{ $phone }}</div>
@endif
<div style="position: absolute; left: 23mm; top: 0.5mm; width: 13mm; font-size: 6pt; line-height: 1.15; font-weight: bold">{{ \Illuminate\Support\Str::limit($store->name, 22, '…') }}</div>
<div style="position: absolute; left: 23mm; top: 10mm; width: 13mm; font-size: 8pt; line-height: 1.2; font-weight: bold">{{ $parts[0] }}<br>{{ $parts[1] ?? '' }}<br>{{ $parts[2] ?? '' }}</div>
