{{--
    <x-admin.dropdown-item href="…" icon="printer">Print</x-admin.dropdown-item>
    <x-admin.dropdown-item type="submit" form="duplicate-form" icon="document-duplicate">Duplicate</x-admin.dropdown-item>
    Props: href, icon, danger, type (button|submit)
--}}
@props(['href' => null, 'icon' => null, 'danger' => false, 'type' => 'button'])
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class(['dropdown__item', 'dropdown__item--danger' => $danger]) }} role="menuitem">
        @if ($icon)<x-admin.icon :name="$icon" />@endif{{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class(['dropdown__item', 'dropdown__item--danger' => $danger]) }} role="menuitem">
        @if ($icon)<x-admin.icon :name="$icon" />@endif{{ $slot }}
    </button>
@endif
