{{-- Catalogue filters (archive data: $facets, $listing, $formAction, $searchTerm). Re-rendered by AJAX (ajax-catalog). --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $price = $facets['price'];
    $symbol = config('commerce.currency.symbol', '£');
@endphp
<form class="filters" method="get" action="{{ $formAction }}" data-filters>
    @if (! empty($searchTerm))
        <input type="hidden" name="s" value="{{ $searchTerm }}">
        <input type="hidden" name="post_type" value="product">
    @endif
    @if ($listing->orderby !== '')
        <input type="hidden" name="orderby" value="{{ $listing->orderby }}">
    @endif
    @if ($price['max'] > $price['min'])
        <details class="filter" open>
            <summary class="filter__title">Price{!! $S::icon('chevron-down', 16) !!}</summary>
            <div class="filter__body price-filter">
                <label><span class="sr-only">Minimum price</span><span class="price-filter__symbol" aria-hidden="true">{{ $symbol }}</span>
                    <input type="number" name="min_price" inputmode="numeric" min="{{ $price['min'] }}" max="{{ $price['max'] }}" step="1" placeholder="{{ $price['min'] }}" value="{{ $price['from'] }}"></label>
                <span aria-hidden="true">–</span>
                <label><span class="sr-only">Maximum price</span><span class="price-filter__symbol" aria-hidden="true">{{ $symbol }}</span>
                    <input type="number" name="max_price" inputmode="numeric" min="{{ $price['min'] }}" max="{{ $price['max'] }}" step="1" placeholder="{{ $price['max'] }}" value="{{ $price['to'] }}"></label>
                <button type="submit" class="btn btn--outline btn--sm">Go</button>
            </div>
        </details>
    @endif
    @foreach ($facets['groups'] as $group)
        @continue(! $group['items'])
        <details class="filter" @if ($group['active'] || $loop->index < 4) open @endif>
            <summary class="filter__title">{{ $group['title'] }}@if ($group['active'])<span class="filter__count">{{ count($group['selected']) }}</span>@endif{!! $S::icon('chevron-down', 16) !!}</summary>
            <ul class="filter__body filter__options">
                @foreach ($group['items'] as $item)
                    @php $id = 'f-'.$group['slug'].'-'.$item['slug']; @endphp
                    <li>
                        <input type="{{ $group['type'] === 'radio' ? 'radio' : 'checkbox' }}" id="{{ $id }}" name="{{ $group['param'] }}{{ $group['type'] === 'radio' ? '' : '[]' }}" value="{{ $item['slug'] }}" @checked($item['selected']) @disabled($item['count'] === 0 && ! $item['selected']) data-filter-input>
                        <label for="{{ $id }}">
                            @if (! empty($item['image']))<img src="{{ $item['image'] }}" alt="" width="28" height="28" loading="lazy">@endif
                            <span>{{ $item['label'] }}</span>
                            <span class="filter__n">{{ $item['count'] }}</span>
                        </label>
                    </li>
                @endforeach
            </ul>
        </details>
    @endforeach
    <noscript><button type="submit" class="btn btn--primary btn--block">Apply filters</button></noscript>
</form>
