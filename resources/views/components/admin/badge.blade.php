{{--
    <x-admin.badge color="success">Active</x-admin.badge>
    <x-admin.badge color="attention" dot>Unfulfilled</x-admin.badge>
    Props: color (gray|success|warning|attention|danger|info|primary|dark|outline), icon (mini heroicon), dot, size (sm)
--}}
@props(['color' => 'gray', 'icon' => null, 'dot' => false, 'size' => null])
<span {{ $attributes->class(['badge', 'badge--'.($color ?: 'gray'), 'badge--sm' => $size === 'sm']) }}>
    @if ($dot)<span class="badge__dot" aria-hidden="true"></span>@endif
    @if ($icon)<x-admin.icon :name="$icon" variant="mini" />@endif
    {{ $slot }}
</span>
