@extends('commerce::admin.layouts.app')

@section('title', $q !== '' ? 'Search: '.$q : 'Search')

@section('content')
    <x-admin.page-header title="Search" :subtitle="$q !== '' ? 'Results for “'.$q.'”' : 'Find orders, products and customers'" />

    <x-admin.card flush class="mb-4">
        <form method="GET" action="{{ route('admin.search') }}" class="filter-bar" role="search">
            <div class="search-input">
                <x-admin.icon name="magnifying-glass" />
                <label for="search-page-q" class="sr-only">Search</label>
                <input type="search" name="q" id="search-page-q" class="input" value="{{ $q }}" placeholder="Order number, email, name, product or SKU" data-page-search autofocus>
            </div>
            <x-admin.button type="submit" variant="primary">Search</x-admin.button>
        </form>
    </x-admin.card>

    @if ($q !== '' && empty($groups))
        <div class="card">
            <x-admin.empty icon="magnifying-glass" title="No results" :description="'Nothing matches “'.$q.'”. Try an order number, an email address, part of a name or a SKU.'" />
        </div>
    @endif

    <div class="stack">
        @foreach ($groups as $group)
            <x-admin.card flush :title="$group['label']" :subtitle="count($group['items']).(count($group['items']) >= 25 ? '+' : '').' found'">
                @if ($group['more'])
                    <x-slot:actions><x-admin.button variant="plain" :href="$group['more']">Open in {{ strtolower($group['label']) }}</x-admin.button></x-slot:actions>
                @endif
                <ul class="list mt-2">
                    @foreach ($group['items'] as $item)
                        <li>
                            <a href="{{ $item['url'] }}" class="list__item">
                                <span class="thumb thumb--sm">
                                    @if ($item['image'])<img src="{{ $item['image'] }}" alt="" loading="lazy">@else<x-admin.icon :name="$item['icon']" />@endif
                                </span>
                                <span class="list__main">
                                    <span class="list__title" style="display:block">{{ $item['title'] }}</span>
                                    <span class="list__sub" style="display:block">{{ $item['subtitle'] }}</span>
                                </span>
                                @if ($item['badge'])<x-admin.badge :color="$item['badge']['color']" dot>{{ $item['badge']['label'] }}</x-admin.badge>@endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>
        @endforeach
    </div>
@endsection
