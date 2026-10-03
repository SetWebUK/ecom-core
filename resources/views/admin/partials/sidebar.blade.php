{{-- Rendered by Pine\Commerce\View\Components\Admin\Sidebar (<x-admin.sidebar />). Edit the menu there, not here. --}}
<aside class="sidebar" :class="{ 'is-open': sidebarOpen }" id="admin-sidebar" aria-label="Main navigation">
    <nav class="sidebar__scroll">
        <ul class="nav">
            @foreach ($sections as $item)
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
