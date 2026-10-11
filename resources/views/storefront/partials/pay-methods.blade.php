{{-- Choix du moyen de paiement en ligne ; $methods : [code => libellé] --}}
<div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Moyen de paiement">
    @foreach ($methods as $code => $label)
        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white p-3 text-sm has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
            <input type="radio" name="{{ $name ?? 'payment_method' }}" value="{{ $code }}" class="accent-[var(--brand)]" @checked($loop->first)> {{ $label }}
        </label>
    @endforeach
</div>
