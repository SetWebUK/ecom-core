{{-- Product card (grid tile). $product (eager-load ProductPresenter::CARD_RELATIONS), optional $heading ('h2'|'h3'). --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $P = commerce_presenter();
    $image = $product->images->first();
    $second = $product->images->skip(1)->first();
    $inStock = $P::inStock($product);
    $sale = $P::sale($product);
    $isVariable = $product->type === 'variable';
    $heading = $heading ?? 'h3';
    $condition = $P::condition($product);
@endphp
<article class="product-card {{ $inStock ? '' : 'is-out' }}">
    {{-- images: the "card" size (commerce.images.sizes) + srcset of the existing sizes; width/height = real pixels --}}
    <a class="product-card__media {{ $second ? 'has-alt' : '' }}" href="{{ $product->url }}" tabindex="-1" aria-hidden="true">
        <x-media-image :path="$image?->path" size="card" :sizes="$imageSizes ?? '(min-width: 1100px) 280px, (min-width: 700px) 30vw, 46vw'" />
        @if ($second)
            <x-media-image class="product-card__alt" :path="$second->path" size="card" :sizes="$imageSizes ?? '(min-width: 1100px) 280px, (min-width: 700px) 30vw, 46vw'" />
        @endif
    </a>
    <div class="product-card__badges">
        @if ($sale)
            <span class="badge badge--accent">-{{ $sale['percent'] }}%</span>
        @endif
        @if (! $inStock)
            <span class="badge badge--muted">Sold out</span>
        @endif
        @if ($condition)
            <span class="badge badge--soft">{{ $condition['label'] }}</span>
        @endif
    </div>
    <div class="product-card__tools">
        @if (commerce_feature('wishlist'))
            <button type="button" class="icon-btn icon-btn--float" aria-label="Add {{ $product->name }} to wishlist" aria-pressed="false" data-wishlist="{{ $product->id }}">{!! $S::icon('heart', 18) !!}</button>
        @endif
        @if (commerce_feature('quick_view'))
            <button type="button" class="icon-btn icon-btn--float" aria-label="Quick view: {{ $product->name }}" data-quick-view="{{ route('product.quick-view', $product) }}">{!! $S::icon('eye', 18) !!}</button>
        @endif
    </div>
    <div class="product-card__body">
        @if ($product->primaryCategory)
            <p class="product-card__cat">{{ $product->primaryCategory->name }}</p>
        @endif
        <{{ $heading }} class="product-card__title"><a href="{{ $product->url }}">{{ $product->name }}</a></{{ $heading }}>
        @if ((int) $product->review_count > 0 && commerce_feature('reviews'))
            <p class="rating" aria-label="Rated {{ number_format((float) $product->average_rating, 1) }} out of 5">
                @for ($i = 1; $i <= 5; $i++)<span class="rating__star {{ $i <= round((float) $product->average_rating) ? 'is-on' : '' }}">{!! $S::icon('star', 14) !!}</span>@endfor
                <span class="rating__count">({{ $product->review_count }})</span>
            </p>
        @endif
        <div class="product-card__foot">
            <p class="price">{!! $P::priceHtml($product) !!}</p>
            @if ($inStock && ! $isVariable)
                <button type="button" class="btn btn--primary btn--sm" data-add-to-cart="{{ $product->id }}" aria-label="Add {{ $product->name }} to basket">{!! $S::icon('bag', 17) !!}<span>Add</span></button>
            @else
                <a class="btn btn--outline btn--sm" href="{{ $product->url }}" aria-label="{{ $inStock ? 'Choose options for' : 'View' }} {{ $product->name }}">{{ $inStock ? 'Options' : 'View' }}</a>
            @endif
        </div>
    </div>
</article>
