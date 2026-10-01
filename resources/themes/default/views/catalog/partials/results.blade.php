{{-- Results column of a product archive (toolbar, active filters, grid, pagination). Re-rendered by AJAX. --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $sortLabels = ['' => 'Featured', 'popularity' => 'Best selling', 'price' => 'Price: low to high', 'price-desc' => 'Price: high to low', 'date' => 'Newest first', 'title' => 'Name: A–Z', 'title-desc' => 'Name: Z–A'];
    $clearUrl = $formAction.(! empty($searchTerm) ? '?'.http_build_query(['s' => $searchTerm, 'post_type' => 'product']) : '');
@endphp
<div class="results" data-results>
    <div class="results__toolbar">
        <p class="results__count" role="status">
            @if ($products->total() === 0)
                No products found
            @elseif ($products->total() <= $products->perPage())
                {{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }}
            @else
                Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }} products
            @endif
        </p>
        <button type="button" class="btn btn--outline btn--sm results__filter-btn" data-drawer-open="catalog-filters" aria-controls="catalog-filters">{!! $S::icon('filter', 18) !!}<span>Filters</span>@if ($activeFilters)<span class="filter__count">{{ count($activeFilters) }}</span>@endif</button>
        <form class="sort-form" method="get" action="{{ $formAction }}" data-sort-form>
            @foreach (request()->except(['orderby', 'page']) as $key => $value)
                @if (is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
            @endforeach
            <label for="orderby" class="sr-only">Sort products</label>
            <select name="orderby" id="orderby" data-sort>
                @foreach ($sortLabels as $key => $label)
                    <option value="{{ $key }}" @selected($listing->orderby === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="btn btn--sm">Sort</button></noscript>
        </form>
    </div>
    @if ($activeFilters)
        <ul class="chips" aria-label="Active filters">
            @foreach ($activeFilters as $active)
                @php
                    $query = array_filter(array_merge(request()->except(['page']), $active['remove']), fn ($v) => $v !== null && $v !== '' && $v !== []);
                    $removeUrl = $formAction.($query ? '?'.http_build_query($query) : '');
                @endphp
                <li><a class="chip" href="{{ $removeUrl }}" data-filter-link aria-label="Remove filter {{ $active['label'] }}: {{ $active['value'] }}"><span class="chip__label">{{ $active['label'] }}:</span> {{ $active['value'] }} {!! $S::icon('close', 14) !!}</a></li>
            @endforeach
            <li><a class="chip chip--clear" href="{{ $clearUrl }}" data-filter-link>Clear all</a></li>
        </ul>
    @endif
    @if ($products->isEmpty())
        <div class="empty">
            {!! $S::icon('search', 40) !!}
            <h2>No products match</h2>
            <p>Try removing a filter or searching for something else.</p>
            @if ($activeFilters)<a class="btn btn--primary" href="{{ $clearUrl }}" data-filter-link>Clear filters</a>@endif
        </div>
    @else
        <div class="product-grid">
            @foreach ($products as $product)
                @include('partials.product-card', ['product' => $product, 'heading' => 'h2'])
            @endforeach
        </div>
        {{ $products->links() }}
    @endif
</div>
