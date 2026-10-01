{{--
    Status tabs with counts (links; other filters are kept, page resets).
    <x-admin.status-tabs :current="$status" :tabs="[
        'all' => ['label' => 'All', 'count' => 12],
        'active' => ['label' => 'Active', 'count' => 3],
        'expired' => 'Expired',
    ]" />
    Props: tabs, current, param (default "status"), default (tab that means "no filter", default "all")
--}}
@props(['tabs' => [], 'current' => null, 'param' => 'status', 'default' => 'all'])
@php $current = $current ?? (is_scalar(request($param)) ? request($param) : null) ?? $default; @endphp
<nav {{ $attributes->class(['tabs']) }} aria-label="Filter by status">
    @foreach ($tabs as $key => $tab)
        @php
            $tab = is_array($tab) ? $tab : ['label' => $tab];
            $url = (string) $key === (string) $default ? request()->fullUrlWithoutQuery([$param, 'page']) : request()->fullUrlWithQuery([$param => $key, 'page' => null]);
            $isActive = (string) $current === (string) $key;
        @endphp
        <a href="{{ $url }}" @class(['tab', 'is-active' => $isActive]) @if ($isActive) aria-current="page" @endif>
            {{ $tab['label'] }}
            @isset($tab['count'])<span class="tab__count">{{ number_format($tab['count']) }}</span>@endisset
        </a>
    @endforeach
</nav>
