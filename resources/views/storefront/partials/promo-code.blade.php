{{-- Code promo du panier ; $summary vient de CartService::summary(). Pas de <form> : la page de commande en est déjà un --}}
@php($discount = $summary['discount'])
<div data-promo class="mt-4 text-sm">
    @if ($discount && ! $discount['error'])
        <p class="flex items-center justify-between gap-2 rounded-lg bg-brand-soft px-3 py-2">
            <span>Code <strong>{{ $discount['code'] }}</strong> appliqué · {{ $discount['label'] }}</span>
            <button type="button" data-promo-remove class="text-slate-600 underline">Retirer</button>
        </p>
    @else
        <label for="promo-code" class="field-label">Code promo</label>
        <div class="flex gap-2">
            <input id="promo-code" data-promo-input autocomplete="off" autocapitalize="characters" maxlength="40" value="{{ $discount['code'] ?? '' }}" class="field uppercase">
            <button type="button" data-promo-apply class="btn-outline shrink-0 px-4">Appliquer</button>
        </div>
        <p data-promo-error class="mt-1 text-red-700" @if (! ($discount['error'] ?? null)) hidden @endif>{{ $discount['error'] ?? '' }}</p>
    @endif
</div>
