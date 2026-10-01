{{-- Buy box: title, rating, price, stock, variations, quantity, add to basket, wishlist, stock alert, trust points. --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $P = commerce_presenter();
    $inStock = $P::inStock($product);
    $isVariable = $product->type === 'variable' && ! empty($variationData);
    $stock = $P::stockDelivery($product);
    $condition = $P::condition($product);
    $payIn3 = $P::payIn3($P::price($product));
    $maxQty = $product->sold_individually ? 1 : ($product->manage_stock && $product->stock_quantity !== null && ! in_array($product->backorders, ['notify', 'yes'], true) ? max(1, (int) $product->stock_quantity) : 99);
    $short = trim((string) $product->short_description);
@endphp
<div class="buybox" id="buy">
    @if ($product->primaryCategory)
        <a class="buybox__cat" href="{{ $product->primaryCategory->url }}">{{ $product->primaryCategory->name }}</a>
    @endif
    <h1 class="buybox__title">{{ $product->name }}</h1>
    @if ($product->subtitle)
        <p class="buybox__subtitle">{{ $product->subtitle }}</p>
    @endif
    @if ($reviews->isNotEmpty() && commerce_feature('reviews'))
        <a class="rating" href="#reviews" aria-label="Rated {{ number_format((float) $reviews->avg('rating'), 1) }} out of 5 – read {{ $reviews->count() }} reviews">
            @for ($i = 1; $i <= 5; $i++)<span class="rating__star {{ $i <= round((float) $reviews->avg('rating')) ? 'is-on' : '' }}">{!! $S::icon('star', 16) !!}</span>@endfor
            <span class="rating__count">{{ $reviews->count() }} {{ \Illuminate\Support\Str::plural('review', $reviews->count()) }}</span>
        </a>
    @endif
    <p class="price price--lg" data-price>{!! $P::priceHtml($product) !!}</p>
    @if ($badge = $P::saveBadge($product))
        <p class="badge badge--accent">{{ $badge }}</p>
    @endif
    @if ($payIn3)
        <p class="buybox__note">Or 3 interest-free payments of {{ money($payIn3) }}</p>
    @endif
    @if ($condition)
        <p class="buybox__condition"><strong>Condition:</strong> {{ $condition['label'] }}@if ($condition['description']) – <span class="muted">{{ $condition['description'] }}</span>@endif</p>
    @endif
    @if ($short !== '')
        <div class="buybox__short prose">{!! \Pine\Commerce\View\Components\PageContent::process($short) !!}</div>
    @endif

    <p class="stock stock--{{ $stock['status'] }}" data-stock>
        @if ($stock['status'] === 'out')
            <span class="stock__dot"></span>Out of stock
        @elseif ($stock['status'] === 'low')
            <span class="stock__dot"></span>Only {{ $stock['stock'] }} left – order soon
        @else
            <span class="stock__dot"></span>In stock @if ($stock['from'])· delivered {{ $stock['from'] }} – {{ $stock['to'] }}@endif
        @endif
    </p>

    <form class="product-form" method="post" action="{{ route('cart.add') }}" data-product-form>
        @csrf
        <input type="hidden" name="product_id" value="{{ $product->id }}">
        @if ($isVariable)
            <input type="hidden" name="variation_id" value="0" data-variation-id>
            <script type="application/json" data-variations>{!! json_encode($variationData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
            <div class="variations">
                @foreach ($variationAttributes as $attribute)
                    <div class="field">
                        <label for="attr-{{ $attribute['slug'] }}">{{ $attribute['name'] }}</label>
                        <select id="attr-{{ $attribute['slug'] }}" name="attribute_{{ $attribute['slug'] }}" required data-variation-select="{{ $attribute['slug'] }}">
                            <option value="">Choose {{ strtolower($attribute['name']) }}…</option>
                            @foreach ($attribute['options'] as $option)
                                <option value="{{ $option['slug'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <p class="variations__message muted" data-variation-message aria-live="polite"></p>
            </div>
        @endif
        @if ($inStock)
            <div class="buybox__actions">
                @if ($maxQty > 1)
                    <div class="qty" data-qty>
                        <button type="button" class="qty__btn" aria-label="Decrease quantity" data-qty-step="-1">{!! $S::icon('minus', 16) !!}</button>
                        <label class="sr-only" for="qty">Quantity</label>
                        <input class="qty__input" type="number" id="qty" name="quantity" value="1" min="1" max="{{ $maxQty }}" inputmode="numeric" data-qty-input>
                        <button type="button" class="qty__btn" aria-label="Increase quantity" data-qty-step="1">{!! $S::icon('plus', 16) !!}</button>
                    </div>
                @else
                    <input type="hidden" name="quantity" value="1">
                @endif
                <button type="submit" class="btn btn--primary btn--lg btn--grow" data-add-button @if ($isVariable) disabled @endif>{!! $S::icon('bag', 20) !!}<span>Add to basket</span></button>
                @if (commerce_feature('wishlist'))
                    <button type="button" class="icon-btn icon-btn--outline" aria-label="Add to wishlist" aria-pressed="false" data-wishlist="{{ $product->id }}">{!! $S::icon('heart') !!}</button>
                @endif
            </div>
        @endif
    </form>

    @if (! $inStock && commerce_feature('stock_alerts'))
        @include('product.partials.notify')
    @endif

    <ul class="trust-list">
        <li>{!! $S::icon('truck', 20) !!}<span>Fast, tracked delivery</span></li>
        <li>{!! $S::icon('refresh', 20) !!}<span>Easy returns</span></li>
        <li>{!! $S::icon('lock', 20) !!}<span>Secure checkout</span></li>
    </ul>
    {!! $S::paymentIcons() !!}
</div>
