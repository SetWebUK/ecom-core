{{-- Quick view (HTML fragment for the modal – ProductController@quickView). $product --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $P = commerce_presenter();
    $image = $product->images->first();
    $inStock = $P::inStock($product);
    $short = trim((string) $product->short_description);
@endphp
<div class="quick">
    <div class="quick__media"><x-media-image :path="$image?->path" size="medium" sizes="(min-width: 700px) 360px, 90vw" :alt="$image?->alt ?: $product->name" :lazy="false" /></div>
    <div class="quick__body">
        @if ($product->primaryCategory)<p class="buybox__cat">{{ $product->primaryCategory->name }}</p>@endif
        <h2 class="buybox__title">{{ $product->name }}</h2>
        <p class="price price--lg">{!! $P::priceHtml($product) !!}</p>
        @if ($short !== '')
            <div class="prose">{!! \Illuminate\Support\Str::limit(strip_tags($short, '<p><ul><li><strong><em><br>'), 600) !!}</div>
        @endif
        <div class="quick__actions">
            @if ($inStock && $product->type !== 'variable')
                <button type="button" class="btn btn--primary" data-add-to-cart="{{ $product->id }}">{!! $S::icon('bag', 18) !!}<span>Add to basket</span></button>
            @endif
            <a class="btn btn--outline" href="{{ $product->url }}">View full details</a>
        </div>
    </div>
</div>
