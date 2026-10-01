{{--
    <x-admin.dropdown label="More actions">
        <x-admin.dropdown-item :href="route('admin.print', ['document' => 'invoice', 'orders' => $order->id])" icon="printer" target="_blank">Print invoice</x-admin.dropdown-item>
        <div class="dropdown__sep"></div>
        <x-admin.confirm as="menu-item" :action="…" icon="trash" title="Delete?">Delete</x-admin.confirm>
    </x-admin.dropdown>
    <x-admin.dropdown icon="ellipsis-horizontal" label="Actions" icon-only size="sm" variant="ghost">…</x-admin.dropdown>
    Props: label, icon, icon-only, variant, size, align (right|left), up (open upwards)
--}}
@props(['label' => 'More actions', 'icon' => null, 'iconOnly' => false, 'variant' => 'secondary', 'size' => null, 'align' => 'right', 'up' => false])
<div {{ $attributes->class(['dropdown']) }} x-data="dropdown" @click.outside="close()" @keydown.escape.stop="close(true)">
    <button type="button" x-ref="button" @click="toggle()" :aria-expanded="open.toString()" aria-haspopup="menu"
            @class(['btn', 'btn--'.$variant => $variant !== 'secondary', 'btn--'.$size => $size, 'btn--icon' => $iconOnly])
            @if ($iconOnly) aria-label="{{ $label }}" title="{{ $label }}" @endif>
        @if ($icon)<x-admin.icon :name="$icon" />@endif
        @unless ($iconOnly)<span>{{ $label }}</span><x-admin.icon name="chevron-down" variant="mini" size="sm" />@endunless
    </button>
    <div x-ref="menu" x-show="open" x-cloak x-transition.opacity.duration.100ms role="menu" @keydown="nav($event)"
         @class(['dropdown__menu', 'dropdown__menu--left' => $align === 'left', 'dropdown__menu--up' => $up])>
        {{ $slot }}
    </div>
</div>
