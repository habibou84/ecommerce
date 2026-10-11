@extends('layouts.storefront')

@section('content')
    <div class="py-6">
        <h1 class="text-2xl font-bold sm:text-3xl">{{ $title }}</h1>
        @if ($collection?->description)
            <p class="mt-2 max-w-2xl text-slate-600">{{ $collection->description }}</p>
        @endif
    </div>

    <form method="get" class="mb-6 flex flex-wrap gap-2" role="search">
        @unless ($collection)
            <label for="q" class="sr-only">Rechercher</label>
            <input id="q" name="q" type="search" value="{{ request('q') }}" placeholder="Rechercher un produit" class="field min-w-0 flex-1 basis-48">
        @endunless
        <label for="tri" class="sr-only">Trier</label>
        <select id="tri" name="tri" class="field w-auto" data-autosubmit>
            <option value="">{{ $collection ? 'Sélection' : 'Nouveautés' }}</option>
            <option value="prix-croissant" @selected(request('tri') === 'prix-croissant')>Prix croissant</option>
            <option value="prix-decroissant" @selected(request('tri') === 'prix-decroissant')>Prix décroissant</option>
        </select>
        <noscript><button class="btn-outline">OK</button></noscript>
    </form>

    @if ($cards->isEmpty())
        <p class="py-12 text-center text-slate-500">Aucun produit {{ request('q') ? 'ne correspond à votre recherche' : 'pour le moment' }}.</p>
    @else
        <div class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($cards as $card)
                @include('storefront.partials.card', ['eager' => $loop->index < 2])
            @endforeach
        </div>

        @if ($page->hasPages())
            <nav class="mt-10 flex items-center justify-center gap-3 text-sm" aria-label="Pages">
                @if ($page->previousPageUrl())
                    <a href="{{ $page->previousPageUrl() }}" class="btn-outline px-4 py-2" rel="prev">Précédent</a>
                @endif
                <span class="text-slate-500">Page {{ $page->currentPage() }} sur {{ $page->lastPage() }}</span>
                @if ($page->nextPageUrl())
                    <a href="{{ $page->nextPageUrl() }}" class="btn-outline px-4 py-2" rel="next">Suivant</a>
                @endif
            </nav>
        @endif
    @endif
@endsection
