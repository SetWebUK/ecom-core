{{--
    Filter bar for index pages (GET form). Search submits on Enter; selects submit on change. "/" focuses the search box.
    <x-admin.filters placeholder="Search discounts" :chips="$chips" keep="status">
        <x-admin.filter-select name="type" :options="Pine\Commerce\Models\Coupon::TYPES" placeholder="All types" />
    </x-admin.filters>
    chips: [query param => "Label shown on the chip"] for active filters – each chip removes its param.
    keep: query params to carry over (string or array), e.g. the current status tab. sort/direction/per_page are always kept.
    Props: action (default current URL), search (bool), name (search param, default q), placeholder, chips, keep
--}}
@props(['action' => null, 'search' => true, 'name' => 'q', 'placeholder' => 'Search', 'chips' => [], 'keep' => []])
@php
    $action ??= url()->current();
    $keep = array_unique(array_merge((array) $keep, ['sort', 'direction', 'per_page']));
    $hasFilters = request()->filled($name) || collect($chips)->isNotEmpty();
@endphp
<form method="GET" action="{{ $action }}" role="search" data-no-loading
      {{ $attributes->class(['filter-bar']) }}
      x-data @change="if ($event.target.matches('select, input[type=checkbox], input[type=radio], input[type=date]')) $el.requestSubmit()">
    @foreach ($keep as $param)
        @if (request()->filled($param) && is_scalar(request($param)))
            <input type="hidden" name="{{ $param }}" value="{{ request($param) }}">
        @endif
    @endforeach
    @if ($search)
        <div class="search-input">
            <x-admin.icon name="magnifying-glass" />
            <label class="sr-only" for="filter-{{ $name }}">{{ $placeholder }}</label>
            <input type="search" name="{{ $name }}" id="filter-{{ $name }}" class="input input--sm" value="{{ is_scalar(request($name)) ? request($name) : '' }}"
                   placeholder="{{ $placeholder }}" autocomplete="off" data-page-search enterkeyhint="search">
        </div>
    @endif
    {{ $slot }}
    <button type="submit" class="sr-only">Apply filters</button>
    @if ($hasFilters)
        <a href="{{ $action }}{{ collect($keep)->filter(fn ($p) => request()->filled($p) && is_scalar(request($p)))->isNotEmpty() ? '?'.http_build_query(collect($keep)->mapWithKeys(fn ($p) => [$p => request($p)])->filter(fn ($v) => is_scalar($v) && $v !== '')->all()) : '' }}" class="btn btn--ghost btn--sm"><span>Clear all</span></a>
    @endif
</form>
@if (collect($chips)->isNotEmpty())
    <div class="filter-chips" aria-label="Active filters">
        @foreach ($chips as $param => $chipLabel)
            <a href="{{ request()->fullUrlWithoutQuery([$param, 'page']) }}" class="chip chip--filter chip--link" aria-label="Remove filter: {{ $chipLabel }}">
                <span class="chip__label">{{ $chipLabel }}</span><x-admin.icon name="x-mark" size="xs" />
            </a>
        @endforeach
    </div>
@endif
