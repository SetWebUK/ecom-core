{{-- Product grid for the visual builder's Products block ([product_grid] shortcode, Content\PageDesign::products()): $products, $columns (2–4). --}}
<div class="product-grid product-grid--{{ $columns }} pb-product-grid">
    @foreach ($products as $product)
        @include('partials.product-card', ['product' => $product])
    @endforeach
</div>
