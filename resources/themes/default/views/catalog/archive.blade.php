{{-- Product archive: shop, category and search results (CatalogController::render / SearchController). --}}
@extends('layouts.app')

@section('body_class', 'page-archive archive-'.$context)

@section('content')
<div class="page-head page-head--archive">
    <div class="container">
        @include('partials.breadcrumbs', ['crumbs' => $context === 'search' ? [['label' => 'Search']] : $breadcrumbs])
        <h1 class="page-title">{{ $context === 'search' && ! empty($searchTerm) ? 'Results for “'.$searchTerm.'”' : $heading }}</h1>
        @if ($context === 'category' && isset($category) && trim(strip_tags((string) $category->description)) !== '' && $pageNumber <= 1)
            <div class="page-head__intro prose">{!! $category->description !!}</div>
        @endif
    </div>
</div>
@if ($context === 'category' && isset($category) && $pageNumber <= 1)
    @php $children = $category->children()->where('is_visible', true)->orderBy('sort_order')->orderBy('name')->get(); @endphp
    @if ($children->isNotEmpty())
        <div class="container">
            <ul class="subcats">
                @foreach ($children as $child)
                    <li><a class="chip chip--lg" href="{{ $child->url }}">{{ $child->name }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif
@endif
<div class="container catalog" data-catalog data-ajax-header="{{ config('commerce.catalog.ajax_header', 'X-Commerce-Catalog') }}">
    <aside class="catalog__sidebar drawer drawer--left drawer--static" id="catalog-filters" aria-label="Filters" aria-hidden="false" tabindex="-1">
        <div class="drawer__head">
            <span class="drawer__title">Filters</span>
            <button type="button" class="icon-btn" aria-label="Close filters" data-drawer-close>{!! \Pine\Commerce\Theme\Storefront::icon('close') !!}</button>
        </div>
        <div class="drawer__body" data-sidebar>
            @include('partials.filters')
        </div>
    </aside>
    <div class="catalog__main" data-results-wrap>
        @include('catalog.partials.results')
    </div>
</div>
@if (! empty($seoContent))
    <section class="container seo-content prose">{!! $seoContent !!}</section>
@endif
@endsection
