{{-- Livraison offerte dès un montant : ce qu'il reste à ajouter --}}
@if ($summary['free_shipping'])
    <p data-free-shipping data-threshold="{{ $summary['free_shipping']['threshold'] }}" class="mt-3 rounded-lg bg-brand-soft px-3 py-2 text-sm">
        @if ($summary['free_shipping']['remaining'] > 0)
            Plus que <strong>@money($summary['free_shipping']['remaining'])</strong> pour la livraison offerte 🚚
        @else
            🎉 Livraison offerte !
        @endif
    </p>
@endif
