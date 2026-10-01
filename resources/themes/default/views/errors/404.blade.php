{{-- 404: search + popular categories. Rendered by the framework error handler (theme chain view paths). --}}
@extends('layouts.app')

@section('title', 'Page not found | '.setting('store.name', config('app.name')))
@section('robots', 'noindex, follow')
@section('body_class', 'page-404')

@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    try {
        $cats = \Pine\Commerce\Models\Category::query()->whereNull('parent_id')->where('is_visible', true)->orderBy('sort_order')->orderBy('name')->limit(6)->get();
    } catch (\Throwable $e) {
        $cats = collect();
    }
@endphp

@section('content')
<div class="container container--narrow error-page">
    <p class="error-page__code">404</p>
    <h1 class="page-title">We can’t find that page</h1>
    <p class="muted">It may have moved, or the link might be out of date. Try searching instead.</p>
    <form class="search-form search-form--lg" role="search" method="get" action="{{ url('/') }}/">
        <label class="sr-only" for="search-404">Search products</label>
        <input id="search-404" type="search" name="s" placeholder="Search products…" maxlength="100">
        <input type="hidden" name="post_type" value="product">
        <button type="submit" class="search-form__submit" aria-label="Search">{!! $S::icon('search', 20) !!}</button>
    </form>
    @if ($cats->isNotEmpty())
        <ul class="subcats subcats--center">
            @foreach ($cats as $cat)<li><a class="chip chip--lg" href="{{ $cat->url }}">{{ $cat->name }}</a></li>@endforeach
        </ul>
    @endif
    <p><a class="btn btn--primary" href="{{ url('/') }}/">Back to the home page</a></p>
</div>
@endsection
