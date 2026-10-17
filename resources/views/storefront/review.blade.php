@extends('layouts.storefront')

@section('no-floating-whatsapp', true)

@section('content')
    <div class="mx-auto max-w-lg py-10">
        <h1 class="text-2xl font-bold">Votre avis compte</h1>
        <p class="mt-1 text-slate-600">Commande {{ $order->reference() }} · {{ $store->name }}</p>

        @if (! $open)
            <p class="mt-6 rounded-lg bg-amber-50 p-4 text-amber-900">Vous pourrez donner votre avis une fois votre commande livrée.</p>
        @elseif ($products->isEmpty())
            <p class="mt-6 rounded-lg bg-slate-50 p-4 text-slate-700">Les articles de cette commande ne sont plus en vente. Merci pour votre confiance !</p>
        @else
            <form data-review-form action="/avis/{{ $order->tracking_token }}" class="mt-6 space-y-4">
                @foreach ($products as ['product' => $product, 'review' => $review])
                    <fieldset class="rounded-xl border border-slate-200 bg-white p-4" data-review-product="{{ $product->id }}">
                        <legend class="sr-only">{{ $product->title }}</legend>
                        <div class="flex items-center gap-3">
                            @if ($image = $product->images->first())
                                <img src="{{ $image->urls()['thumb'] }}" alt="" class="h-14 w-14 rounded-lg object-cover" width="56" height="56" loading="lazy">
                            @endif
                            <p class="font-medium">{{ $product->title }}</p>
                        </div>
                        <div class="mt-3 flex gap-1" role="radiogroup" aria-label="Note de {{ $product->title }}">
                            @for ($i = 1; $i <= 5; $i++)
                                <label class="cursor-pointer text-3xl leading-none" data-star="{{ $i }}">
                                    <input type="radio" name="rating-{{ $product->id }}" value="{{ $i }}" class="sr-only" @checked($review?->rating === $i)>
                                    <span aria-hidden="true">★</span><span class="sr-only">{{ $i }} étoile{{ $i > 1 ? 's' : '' }}</span>
                                </label>
                            @endfor
                        </div>
                        <textarea name="comment-{{ $product->id }}" rows="2" maxlength="1000" class="mt-3 w-full rounded-lg border border-slate-300 p-2 text-sm" placeholder="Qu'en avez-vous pensé ? (facultatif)">{{ $review?->comment }}</textarea>
                    </fieldset>
                @endforeach
                <label class="block text-sm">
                    <span class="text-slate-600">Votre prénom (affiché avec l'avis)</span>
                    <input name="name" maxlength="60" class="mt-1 w-full rounded-lg border border-slate-300 p-2" value="{{ \Illuminate\Support\Str::before((string) $order->customer_name, ' ') }}">
                </label>
                <p data-review-error class="text-sm text-red-700" hidden></p>
                <button class="btn-store w-full">Envoyer mon avis</button>
                <p class="text-center text-xs text-slate-500">Votre avis est publié après vérification par la boutique.</p>
            </form>
            <div data-review-thanks class="mt-6 rounded-xl bg-brand-soft p-6 text-center" hidden>
                <p class="text-3xl">✓</p>
                <p class="mt-2 font-semibold" data-review-message>Merci pour votre avis !</p>
            </div>
        @endif
    </div>
@endsection
