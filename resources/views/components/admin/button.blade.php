{{--
    <x-admin.button>Cancel</x-admin.button>
    <x-admin.button variant="primary" type="submit" icon="check">Save</x-admin.button>
    <x-admin.button :href="route('admin.coupons.create')" variant="primary" icon="plus">Create discount</x-admin.button>
    <x-admin.button icon="trash" label="Delete" variant="ghost-danger" />          icon-only (label = aria-label + tooltip)
    Props: variant (secondary|primary|danger|dark|ghost|ghost-danger|plain), size (sm|md|lg), href, type, icon, icon-right, label, block, disabled
--}}
@props(['variant' => 'secondary', 'size' => null, 'href' => null, 'type' => 'button', 'icon' => null, 'iconRight' => null, 'label' => null, 'block' => false, 'disabled' => false])
@php
    $iconOnly = $icon && $slot->isEmpty();
    $classes = ['btn', 'btn--'.$variant => $variant && $variant !== 'secondary', 'btn--'.$size => $size && $size !== 'md', 'btn--icon' => $iconOnly, 'btn--block' => $block, 'is-disabled' => $disabled && $href];
@endphp
@if ($href)
<a href="{{ $disabled ? '#' : $href }}" {{ $attributes->class($classes) }} @if ($disabled) aria-disabled="true" tabindex="-1" @endif @if ($label) aria-label="{{ $label }}" title="{{ $label }}" @endif>
@else
<button type="{{ $type }}" {{ $attributes->class($classes) }} @disabled($disabled) @if ($label) aria-label="{{ $label }}" title="{{ $label }}" @endif>
@endif
    @if ($icon)<x-admin.icon :name="$icon" />@endif
    @if ($slot->isNotEmpty())<span>{{ $slot }}</span>@endif
    @if ($iconRight)<x-admin.icon :name="$iconRight" />@endif
@if ($href)
</a>
@else
</button>
@endif
