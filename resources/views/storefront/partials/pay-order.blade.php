{{-- Payer en ligne le reste d'une commande ; $order, $methods --}}
<form data-pay-form action="{{ $action }}" class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left">
    <p class="font-semibold">{{ $title ?? 'Payer maintenant' }} : @money($amount)</p>
    @include('storefront.partials.pay-methods', ['methods' => $methods, 'name' => 'method'])
    <p data-pay-error class="text-sm text-red-700" hidden></p>
    <button class="btn-store w-full">Payer @money($amount)</button>
    <p class="text-center text-xs text-slate-500">Vous êtes redirigé vers la page sécurisée du prestataire de paiement.</p>
</form>
