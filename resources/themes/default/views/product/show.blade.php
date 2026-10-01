{{-- Single product (ProductController@show): gallery, buy box with variations, details, specifications, reviews, related. --}}
@extends('layouts.app')

@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $P = commerce_presenter();
    $inStock = $P::inStock($product);
    $isVariable = $product->type === 'variable' && ! empty($variationData);
    $specs = $P::specRows($product);
    $attributes = $product->attributeValues->filter(fn ($v) => $v->attribute)
        ->groupBy(fn ($v) => $v->attribute->name)->map(fn ($values) => $values->pluck('value')->implode(', '));
    $description = trim((string) $product->description) !== '' ? \Pine\Commerce\View\Components\PageContent::process((string) $product->description) : '';
    $avg = (float) $product->average_rating;
    $reviewCount = $reviews->count();
@endphp

@section('body_class', 'page-product product-type-'.$product->type)

@push('head')
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endpush

@section('content')
<div class="container">
    @include('partials.breadcrumbs', ['crumbs' => $crumbs])
    @if ($product->status !== 'published')
        <div class="notice notice--warning" role="status">{{ ucfirst($product->status) }} - not visible to customers</div>
    @endif
    @if (session('product_notice'))
        <div class="notice notice--success" role="status">{{ session('product_notice') }}</div>
    @endif
    @if ($errors->any())
        <div class="notice notice--error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="product" data-product>
        @include('product.partials.gallery')
        @include('product.partials.buybox')
    </div>

    <div class="product-details">
        <nav class="tabs" aria-label="Product information">
            @if ($description !== '')<a class="tabs__link" href="#description">Description</a>@endif
            @if ($specs->isNotEmpty() || $attributes->isNotEmpty())<a class="tabs__link" href="#specifications">Specifications</a>@endif
            @if (commerce_feature('reviews'))<a class="tabs__link" href="#reviews">Reviews ({{ $reviewCount }})</a>@endif
        </nav>
        @if ($description !== '')
            <section class="product-section" id="description">
                <h2 class="section-title">Description</h2>
                <div class="prose">{!! $description !!}</div>
            </section>
        @endif
        @if ($specs->isNotEmpty() || $attributes->isNotEmpty())
            <section class="product-section" id="specifications">
                <h2 class="section-title">Specifications</h2>
                <table class="spec-table">
                    <tbody>
                        @if ($product->sku)
                            <tr><th scope="row">SKU</th><td>{{ $product->sku }}</td></tr>
                        @endif
                        @forelse ($specs as $spec)
                            <tr><th scope="row">{{ $spec->label }}</th><td>{{ $spec->value }}</td></tr>
                        @empty
                            @foreach ($attributes as $name => $value)
                                <tr><th scope="row">{{ $name }}</th><td>{{ $value }}</td></tr>
                            @endforeach
                        @endforelse
                    </tbody>
                </table>
            </section>
        @endif
        @if (commerce_feature('reviews'))
            @include('product.partials.reviews')
        @endif
    </div>

    @if ($related->isNotEmpty())
        <section class="section" aria-labelledby="related-title">
            <div class="section__head"><h2 class="section-title" id="related-title">You may also like</h2></div>
            <div class="product-grid product-grid--4">
                @foreach ($related as $item)
                    @include('partials.product-card', ['product' => $item])
                @endforeach
            </div>
        </section>
    @endif
</div>

{{-- Sticky add-to-basket bar on small screens --}}
<div class="sticky-buy" data-sticky-buy hidden>
    <div class="container sticky-buy__inner">
        <div class="sticky-buy__info"><span class="sticky-buy__name">{{ $product->name }}</span><span class="price">{!! $P::priceHtml($product) !!}</span></div>
        <a class="btn btn--primary" href="#buy">{{ $inStock ? 'Add to basket' : 'View options' }}</a>
    </div>
</div>
@endsection
