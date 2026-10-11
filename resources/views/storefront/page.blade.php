@extends('layouts.storefront')

@section('content')
    <article class="mx-auto max-w-2xl py-8">
        <h1 class="mb-6 text-3xl font-bold">{{ $page->title }}</h1>
        <div class="prose-store leading-relaxed text-slate-700">
            {!! \Illuminate\Support\Str::markdown((string) $page->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
        </div>
    </article>
@endsection
