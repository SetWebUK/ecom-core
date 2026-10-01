{{--
    <x-admin.callout type="warning" title="This discount has expired">Customers can no longer use it.</x-admin.callout>
    Props: type (info|success|warning|danger|neutral), title, icon
--}}
@props(['type' => 'info', 'title' => null, 'icon' => null])
@php
    $icon ??= match ($type) { 'success' => 'check-circle', 'warning' => 'exclamation-triangle', 'danger' => 'exclamation-circle', default => 'information-circle' };
@endphp
<div {{ $attributes->class(['callout', 'callout--'.$type => $type !== 'info']) }} role="{{ $type === 'danger' ? 'alert' : 'status' }}">
    <x-admin.icon :name="$icon" />
    <div class="flex-1">
        @if ($title)<p class="callout__title">{{ $title }}</p>@endif
        @if ($slot->isNotEmpty())<div>{{ $slot }}</div>@endif
    </div>
</div>
