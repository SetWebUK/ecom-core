{{-- Rendered by Pine\Commerce\View\Components\Admin\Sidebar (<x-admin.sidebar />). Edit the menu there, not here. --}}
<aside class="sidebar" :class="{ 'is-open': sidebarOpen }" id="admin-sidebar" aria-label="Main navigation">
    {{-- The shop itself (Services\Admin\AdminBrand): its logo on a white tile, its name, a way to the shop --}}
    <div class="sidebar__brand">
        <a href="{{ route('admin.dashboard') }}" class="brand-tile" aria-label="{{ $brand['name'] }} admin home">
            @if ($brand['logo'])
                <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" class="brand-tile__logo">
            @else
                <span class="brand-tile__initials" aria-hidden="true">{{ $brand['initials'] }}</span>
                <span class="brand-tile__name">{{ $brand['name'] }}</span>
            @endif
        </a>
        <div class="sidebar__brand-row">
            <a href="{{ url('/') }}" target="_blank" rel="noopener" class="sidebar__shop-link" title="Open the shop in a new tab">
                <x-admin.icon name="building-storefront" size="sm" /><span>View shop</span><x-admin.icon name="arrow-top-right-on-square" size="sm" class="sidebar__ext" />
            </a>
            @if ($create)
                <div class="dropdown" x-data="dropdown" @click.outside="close()" @keydown.escape.stop="close(true)">
                    <button type="button" class="sidebar__create" x-ref="button" @click="toggle()" :aria-expanded="open.toString()" aria-haspopup="menu">
                        <x-admin.icon name="plus" size="sm" /><span>Create</span>
                    </button>
                    <div class="dropdown__menu dropdown__menu--left" x-ref="menu" x-show="open" x-transition.opacity.duration.100ms role="menu" @keydown="nav($event)" x-cloak>
                        @foreach ($create as $link)
                            <a href="{{ $link['url'] }}" class="dropdown__item" role="menuitem"><x-admin.icon :name="$link['icon']" /> {{ $link['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
    <nav class="sidebar__scroll">
        <ul class="nav">
            @foreach ($sections as $item)
                @if ($item['groupLabel'])
                    <li class="nav__group-label">{{ $item['groupLabel'] }}</li>
                @endif
                <li>
                    <a href="{{ $item['url'] }}"
                       @class(['nav__link', 'is-active' => $item['active'], 'is-disabled' => ! $item['exists']])
                       @if ($item['active'] && empty($item['children'])) aria-current="page" @endif
                       @unless ($item['exists']) aria-disabled="true" title="Not available yet" @endunless>
                        <x-admin.icon :name="$item['icon']" />
                        <span>{{ $item['label'] }}</span>
                        @if ($item['count'])
                            <span class="nav__count {{ $item['label'] === 'Orders' ? 'nav__count--alert' : '' }}" title="{{ $item['countLabel'] }}">
                                {{ $item['count'] > 99 ? '99+' : $item['count'] }}<span class="sr-only"> {{ $item['countLabel'] }}</span>
                            </span>
                        @endif
                    </a>
                    @if ($item['active'] && ! empty($item['children']))
                        <ul class="nav__sub">
                            @foreach ($item['children'] as $child)
                                <li>
                                    <a href="{{ $child['url'] }}"
                                       @class(['nav__link', 'is-active' => $child['active'], 'is-disabled' => ! $child['exists']])
                                       @if ($child['active']) aria-current="page" @endif
                                       @unless ($child['exists']) aria-disabled="true" title="Not available yet" @endunless>
                                        <span>{{ $child['label'] }}</span>
                                        @if ($child['count'])
                                            <span class="nav__count" title="{{ $child['countLabel'] }}">{{ $child['count'] > 99 ? '99+' : $child['count'] }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>
    <div class="sidebar__footer">
        <ul class="nav">
            @if (! empty($import))
                <li>
                    <a href="{{ $import['url'] }}" @class(['nav__link', 'is-active' => $import['active']]) @if ($import['active']) aria-current="page" @endif>
                        <x-admin.icon :name="$import['icon']" />
                        <span>{{ $import['label'] }}</span>
                    </a>
                </li>
            @endif
            @if (! empty($updates))
                <li>
                    <a href="{{ $updates['url'] }}" @class(['nav__link', 'is-active' => $updates['active']]) @if ($updates['active']) aria-current="page" @endif>
                        <x-admin.icon :name="$updates['icon']" />
                        <span>{{ $updates['label'] }}</span>
                        @if ($updates['count'])
                            <span class="nav__count nav__count--alert" title="{{ $updates['countLabel'] }}">1<span class="sr-only"> {{ $updates['countLabel'] }}</span></span>
                        @endif
                    </a>
                </li>
            @endif
            <li>
                <a href="{{ $settings['url'] }}" @class(['nav__link', 'is-active' => $settings['active'], 'is-disabled' => ! $settings['exists']]) @if ($settings['active']) aria-current="page" @endif>
                    <x-admin.icon :name="$settings['icon']" />
                    <span>{{ $settings['label'] }}</span>
                </a>
            </li>
        </ul>
    </div>
</aside>
<div class="sidebar-backdrop" x-show="sidebarOpen" x-transition.opacity @click="closeSidebar()" x-cloak></div>
