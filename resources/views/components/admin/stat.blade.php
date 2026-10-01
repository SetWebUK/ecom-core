{{--
    <x-admin.stat label="Revenue" :value="money($total)" :delta="12.4" hint="vs previous 30 days" icon="banknotes" />
    delta: % change (positive = up). invert: true when down is good (e.g. refunds). href makes the card a link.
--}}
@props(['label', 'value', 'delta' => null, 'hint' => null, 'icon' => null, 'href' => null, 'invert' => false])
@php
    $direction = $delta === null ? null : ($delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'));
    $tone = match (true) {
        $direction === null, $direction === 'flat' => 'flat',
        ($direction === 'up') !== (bool) $invert => 'up',
        default => 'down',
    };
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" style="color:inherit;text-decoration:none" @endif {{ $attributes->class(['card', 'stat']) }}>
    <div class="stat__label">@if ($icon)<x-admin.icon :name="$icon" size="sm" />@endif{{ $label }}</div>
    <div class="stat__value">{{ $value }}</div>
    @if ($direction !== null || $hint)
        <div class="stat__foot">
            @if ($direction !== null)
                <span class="delta delta--{{ $tone }}">
                    <x-admin.icon :name="$direction === 'up' ? 'arrow-trending-up' : ($direction === 'down' ? 'arrow-trending-down' : 'minus')" size="xs" />
                    {{ $direction === 'flat' ? '0' : rtrim(rtrim(number_format(abs($delta), 1), '0'), '.') }}%
                    <span class="sr-only">{{ $direction === 'up' ? 'increase' : ($direction === 'down' ? 'decrease' : 'no change') }}</span>
                </span>
            @endif
            @if ($hint)<span>{{ $hint }}</span>@endif
        </div>
    @endif
</{{ $tag }}>
