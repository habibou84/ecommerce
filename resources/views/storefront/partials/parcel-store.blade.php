{{-- Carte de la boutique (publique) sur la page d'un colis : logo, numéro, lien, contacter ; puis « Crée ta boutique » --}}
@php($contact = $store->whatsapp_phone ?? $store->phone)
<div class="mt-6 flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4">
    @if ($logo)
        <img src="{{ $logo }}" alt="" class="h-12 w-12 shrink-0 rounded-xl object-cover ring-1 ring-slate-200">
    @endif
    <div class="min-w-0 text-sm">
        <p class="font-bold">{{ $store->name }}</p>
        @if ($contact)
            <p>📞 <a href="tel:{{ $contact }}" class="underline">{{ \Jibiat\Socle\PhoneNumber::display($contact) }}</a></p>
        @endif
        @if ($store->storefront_enabled)
            <p class="truncate font-semibold text-emerald-800">🛍️ <a href="{{ $store->url('/') }}">{{ preg_replace('#^https?://#', '', rtrim($store->url('/'), '/')) }}</a></p>
        @endif
    </div>
</div>
<div class="mt-4 flex gap-2">
    @if ($store->whatsapp_phone)
        <a href="https://wa.me/{{ ltrim($store->whatsapp_phone, '+') }}?text={{ rawurlencode($contactText) }}" class="btn-brand flex-1 py-3">Contacter</a>
    @elseif ($store->phone)
        <a href="tel:{{ $store->phone }}" class="btn-brand flex-1 py-3">Appeler</a>
    @endif
    @if ($store->storefront_enabled)
        <a href="{{ $store->url('/') }}" class="flex-1 rounded-xl border border-slate-300 py-3 text-center font-semibold">Voir la boutique</a>
    @endif
</div>
<p class="mt-8 border-t border-slate-200 pt-4 text-center text-sm text-slate-600">
    Boutique gérée avec <b>Jibiat Marchand</b><br>
    <a href="{{ \App\Support\Tenancy::platformUrl('/creer-ma-boutique?utm_source=colis&utm_medium=qr') }}" class="font-semibold text-emerald-800">Tu vends aussi ? Crée ta boutique gratuitement →</a>
</p>
