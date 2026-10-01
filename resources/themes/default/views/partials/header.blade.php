{{-- Site header: announcement bar, logo, search, account / wishlist / basket, main navigation with dropdowns. --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $store = $S::store();
    $cartCount = $S::cartCount();
    $mainMenu = menu_tree(theme_config('menus.main', ['main']), theme_config('menus.fallbacks.main', []));
    $topText = trim((string) setting('store.top_bar_text', ''));
    $showTop = filter_var(theme_setting('show_top_bar', true), FILTER_VALIDATE_BOOL) && $topText !== '';
    $topLinkText = trim((string) setting('store.top_bar_link_text', ''));
    $topLinkUrl = \Pine\Commerce\View\Components\MenuComponent::href(setting('store.top_bar_link_url'));
    $href = fn ($url) => \Pine\Commerce\View\Components\MenuComponent::href($url) ?? '#';
    $searchTerm = request('s', request('q', ''));
@endphp
@if ($showTop)
    <div class="topbar">
        <div class="container topbar__inner">
            <p class="topbar__text">{{ $topText }}@if ($topLinkText !== '' && $topLinkUrl && $topLinkUrl !== url('/').'#') <a href="{{ $topLinkUrl }}">{{ $topLinkText }}</a>@endif</p>
            @if ($store['phone'] !== '')
                <a class="topbar__phone" href="{{ $S::tel($store['phone']) }}">{!! $S::icon('phone', 15) !!}<span>{{ $store['phone'] }}</span></a>
            @endif
        </div>
    </div>
@endif
<header class="site-header" data-site-header>
    <div class="container site-header__bar">
        <button type="button" class="icon-btn site-header__burger" aria-label="Open menu" aria-controls="mobile-menu" aria-expanded="false" data-drawer-open="mobile-menu">{!! $S::icon('menu') !!}</button>
        <a class="site-logo" href="{{ url('/') }}/" rel="home">
            @if ($store['logo'])
                <img src="{{ $store['logo'] }}" alt="{{ $store['name'] }}">
            @else
                <span class="site-logo__text">{{ $store['name'] }}</span>
            @endif
        </a>
        <form class="search-form" role="search" method="get" action="{{ url('/') }}/" data-search>
            <label class="sr-only" for="site-search">Search products</label>
            <input id="site-search" type="search" name="s" value="{{ $searchTerm }}" placeholder="Search products…" autocomplete="off" maxlength="100"
                   aria-autocomplete="list" aria-controls="search-suggestions" aria-expanded="false" data-search-input>
            <input type="hidden" name="post_type" value="product">
            <button type="submit" class="search-form__submit" aria-label="Search">{!! $S::icon('search', 20) !!}</button>
            <div class="search-suggest" id="search-suggestions" role="listbox" hidden data-search-results></div>
        </form>
        <nav class="header-actions" aria-label="Account and basket">
            <button type="button" class="icon-btn header-actions__search-toggle" aria-label="Search" data-search-toggle>{!! $S::icon('search') !!}</button>
            <a class="icon-btn" href="{{ route('account') }}" aria-label="{{ auth()->check() ? 'My account' : 'Sign in' }}">{!! $S::icon('user') !!}</a>
            @if (commerce_feature('wishlist'))
                <a class="icon-btn header-actions__wishlist" href="{{ auth()->check() ? route('account.wishlist') : route('account') }}" aria-label="Wishlist">{!! $S::icon('heart') !!}</a>
            @endif
            <a class="icon-btn cart-btn" href="{{ url('basket') }}" aria-label="Basket, {{ $cartCount }} {{ \Illuminate\Support\Str::plural('item', $cartCount) }}" data-cart-open>
                {!! $S::icon('bag') !!}
                <span class="cart-btn__count" data-cart-count @if ($cartCount < 1) hidden @endif>{{ $cartCount }}</span>
            </a>
        </nav>
    </div>
    @if ($mainMenu)
        <nav class="main-nav" aria-label="Main">
            <div class="container">
                <ul class="main-nav__list">
                    @foreach ($mainMenu as $i => $item)
                        @php $hasChildren = ! empty($item['children']); @endphp
                        <li class="main-nav__item {{ $hasChildren ? 'has-children' : '' }} {{ $item['class'] }}" @if ($hasChildren) data-dropdown @endif>
                            <a class="main-nav__link" href="{{ $href($item['url']) }}" @if ($item['new_tab']) target="_blank" rel="noopener" @endif @if (\Pine\Commerce\View\Components\MenuComponent::isCurrent($href($item['url']))) aria-current="page" @endif>{{ $item['label'] }}@if ($item['badge'])<span class="badge badge--accent">{{ $item['badge'] }}</span>@endif</a>
                            @if ($hasChildren)
                                <button type="button" class="main-nav__toggle" aria-expanded="false" aria-controls="nav-dd-{{ $i }}" aria-label="Show {{ $item['label'] }} menu" data-dropdown-toggle>{!! $S::icon('chevron-down', 16) !!}</button>
                                <div class="dropdown {{ collect($item['children'])->contains(fn ($c) => ! empty($c['children'])) ? 'dropdown--mega' : '' }}" id="nav-dd-{{ $i }}">
                                    <ul class="dropdown__list">
                                        @foreach ($item['children'] as $child)
                                            <li class="dropdown__item">
                                                <a class="dropdown__link {{ ! empty($child['children']) ? 'dropdown__link--heading' : '' }}" href="{{ $href($child['url']) }}" @if ($child['new_tab']) target="_blank" rel="noopener" @endif>
                                                    @if ($child['icon'] && ($img = \Pine\Commerce\View\Components\MenuComponent::image($child['icon'])))<img src="{{ $img }}" alt="" loading="lazy" width="40" height="40">@endif
                                                    <span>{{ $child['label'] }}</span>
                                                </a>
                                                @if (! empty($child['children']))
                                                    <ul class="dropdown__sub">
                                                        @foreach ($child['children'] as $grand)
                                                            <li><a href="{{ $href($grand['url']) }}">{{ $grand['label'] }}</a></li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </nav>
    @endif
</header>
@include('partials.mobile-menu', ['items' => menu_tree(theme_config('menus.mobile', ['mobile_nav', 'mobile', 'main']), theme_config('menus.fallbacks.mobile', []))])
