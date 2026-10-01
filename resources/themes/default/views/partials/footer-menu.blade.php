{{-- A footer link column (variant "column", with $title) or an inline row of links (variant "row"). $items, $location, $variant --}}
@php
    $href = fn ($url) => \Pine\Commerce\View\Components\MenuComponent::href($url) ?? '#';
    $variant = $variant ?? 'column';
@endphp
@if ($variant === 'row')
    <ul class="footer-links footer-links--row">
        @foreach ($items as $item)
            <li><a href="{{ $href($item['url']) }}" @if ($item['new_tab']) target="_blank" rel="noopener" @endif>{{ $item['label'] }}</a></li>
        @endforeach
    </ul>
@else
    <nav class="footer-col" aria-label="{{ $title ?? $location }}">
        <h2 class="footer-col__title">{{ $title ?? '' }}</h2>
        <ul class="footer-links">
            @foreach ($items as $item)
                <li><a href="{{ $href($item['url']) }}" @if ($item['new_tab']) target="_blank" rel="noopener" @endif>{{ $item['label'] }}</a></li>
            @endforeach
        </ul>
    </nav>
@endif
