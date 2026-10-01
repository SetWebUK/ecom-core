@php
    $user = auth()->user();
    $initials = collect(preg_split('/\s+/', trim($user->full_name ?: $user->email)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    $storeName = setting('store.name', config('app.name'));
@endphp
<header class="topbar">
    <button type="button" class="btn--topbar topbar__menu-btn" @click="toggleSidebar()" :aria-expanded="sidebarOpen.toString()" aria-controls="admin-sidebar" aria-label="Menu" :aria-label="sidebarOpen ? 'Close menu' : 'Open menu'">
        <x-admin.icon name="bars-3" x-show="!sidebarOpen" />
        <x-admin.icon name="x-mark" x-show="sidebarOpen" x-cloak />
    </button>
    <a href="{{ route('admin.dashboard') }}" class="topbar__brand" aria-label="{{ $storeName }} admin home">
        <span class="topbar__mark"><img src="{{ commerce_admin_brand('mark') }}" alt="" width="24" height="24"></span>
        <span class="topbar__store">{{ $storeName }}</span>
    </a>

    <div class="topbar__search">
        <form class="gsearch" role="search" action="{{ route('admin.search') }}" method="GET"
              x-data="globalSearch(@js(route('admin.search.suggest')), @js(route('admin.search')))"
              @submit.prevent="go()" @click.outside="close()" @keydown.escape="close(); $refs.input.blur()">
            <label for="global-search" class="sr-only">Search orders, products and customers</label>
            <input id="global-search" x-ref="input" name="q" type="search" class="gsearch__input" autocomplete="off" spellcheck="false"
                   placeholder="Search orders, products, customers" x-model="q"
                   @focus="if (items.length) open = true" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                   role="combobox" aria-autocomplete="list" aria-controls="global-search-results" :aria-expanded="open.toString()"
                   :aria-activedescendant="active >= 0 ? 'gs-item-' + active : null">
            <x-admin.icon name="magnifying-glass" size="sm" class="gsearch__icon" />
            <span class="gsearch__kbd" aria-hidden="true"><kbd>/</kbd></span>

            <div class="gsearch__panel" id="global-search-results" role="listbox" x-show="open && q.trim().length >= 2" x-transition.opacity.duration.100ms x-cloak>
                <template x-if="loading && !items.length">
                    <div class="gsearch__empty"><span class="spinner" style="margin:0 auto"></span></div>
                </template>
                <template x-if="!loading && searched && !items.length">
                    <div class="gsearch__empty">No orders, products or customers match “<span x-text="searched"></span>”.</div>
                </template>
                <template x-for="group in groups" :key="group.key">
                    <div class="gsearch__group">
                        <div class="gsearch__heading" x-text="group.label"></div>
                        <template x-for="item in group.items" :key="group.key + item.id">
                            <a :href="item.url" class="gsearch__item" :class="{ 'is-active': item._i === active }" :id="'gs-item-' + item._i" :data-index="item._i"
                               role="option" :aria-selected="(item._i === active).toString()" @mouseenter="active = item._i">
                                <span class="thumb thumb--sm">
                                    <template x-if="item.image"><img :src="item.image" alt="" loading="lazy"></template>
                                    <template x-if="!item.image"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><use :href="@js(commerce_admin_asset('img/icons.svg', false)) + '#' + item.icon"></use></svg></template>
                                </span>
                                <span class="gsearch__item-main">
                                    <span class="gsearch__item-title" x-text="item.title"></span>
                                    <span class="gsearch__item-sub" x-text="item.subtitle"></span>
                                </span>
                                <template x-if="item.badge"><span class="badge" :class="'badge--' + item.badge.color" x-text="item.badge.label"></span></template>
                            </a>
                        </template>
                    </div>
                </template>
                <div class="gsearch__footer" x-show="items.length">
                    <span><kbd>↑</kbd> <kbd>↓</kbd> to move · <kbd>Enter</kbd> to open</span>
                    <a :href="@js(route('admin.search')) + '?q=' + encodeURIComponent(q.trim())">See all results</a>
                </div>
            </div>
        </form>
    </div>

    <div class="topbar__actions">
        <a href="{{ url('/') }}" target="_blank" rel="noopener" class="btn--topbar" title="Open the shop in a new tab">
            <x-admin.icon name="building-storefront" size="sm" />
            <span class="view-store-label">View store</span>
        </a>
        <div class="dropdown" x-data="dropdown" @click.outside="close()" @keydown.escape.stop="close(true)">
            <button type="button" class="btn--topbar" x-ref="button" @click="toggle()" :aria-expanded="open.toString()" aria-haspopup="menu" aria-label="Account menu">
                <span class="avatar" aria-hidden="true">{{ $initials ?: '?' }}</span>
                <span class="hidden-mobile">{{ $user->first_name ?: \Illuminate\Support\Str::before($user->name, ' ') }}</span>
            </button>
            <div class="dropdown__menu" x-ref="menu" x-show="open" x-transition.opacity.duration.100ms role="menu" @keydown="nav($event)" x-cloak>
                <div class="dropdown__user">
                    <span class="avatar avatar--lg" aria-hidden="true">{{ $initials ?: '?' }}</span>
                    <span class="flex-1" style="min-width:0">
                        <span class="fw-600 truncate" style="display:block">{{ $user->full_name }}</span>
                        <span class="text-xs text-muted truncate" style="display:block">{{ $user->email }}</span>
                        <span class="text-xs text-muted">{{ \Pine\Commerce\Models\User::ROLES[$user->role] ?? $user->role }}</span>
                    </span>
                </div>
                <a href="{{ route('admin.profile.edit') }}" class="dropdown__item" role="menuitem"><x-admin.icon name="user-circle" /> Your profile</a>
                @if ($user->isAdmin() && Route::has('admin.staff.index'))
                    <a href="{{ route('admin.staff.index') }}" class="dropdown__item" role="menuitem"><x-admin.icon name="users" /> Staff accounts</a>
                @endif
                <a href="{{ url('/') }}" target="_blank" rel="noopener" class="dropdown__item" role="menuitem"><x-admin.icon name="arrow-top-right-on-square" /> View store</a>
                @if (Route::has('admin.settings.theme'))
                    <a href="{{ route('admin.settings.theme') }}#preview" class="dropdown__item" role="menuitem"><x-admin.icon name="eye" /> Preview theme</a>
                @endif
                <div class="dropdown__sep"></div>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="dropdown__item" role="menuitem"><x-admin.icon name="arrow-right-start-on-rectangle" /> Sign out</button>
                </form>
            </div>
        </div>
    </div>
</header>
