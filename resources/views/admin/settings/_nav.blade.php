{{-- Settings sub-navigation (left column). $active = group key. --}}
@php use Pine\Commerce\Services\Admin\StoreSettings; @endphp
<nav class="settings-nav" aria-label="Settings">
    @foreach (StoreSettings::groups() as $key => $item)
        @continue(($item['admin'] ?? false) && ! auth()->user()?->isAdmin())
        <a href="{{ StoreSettings::url($key) }}" @class(['settings-nav__link', 'is-active' => $active === $key]) @if ($active === $key) aria-current="page" @endif>
            <x-admin.icon :name="$item['icon']" /><span>{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
