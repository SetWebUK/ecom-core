{{-- Mobile off-canvas menu. $items = normalised menu tree (menu_tree()); rendered by the header and by <x-mobile-menu>. --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $store = $S::store();
    $href = fn ($url) => \Pine\Commerce\View\Components\MenuComponent::href($url) ?? '#';
    $items = $items ?? [];
@endphp
<aside class="drawer drawer--left mobile-menu" id="mobile-menu" role="dialog" aria-modal="true" aria-label="Menu" aria-hidden="true" tabindex="-1">
    <div class="drawer__head">
        <span class="drawer__title">Menu</span>
        <button type="button" class="icon-btn" aria-label="Close menu" data-drawer-close>{!! $S::icon('close') !!}</button>
    </div>
    <div class="drawer__body">
        <form class="search-form search-form--drawer" role="search" method="get" action="{{ url('/') }}/">
            <label class="sr-only" for="mobile-search">Search products</label>
            <input id="mobile-search" type="search" name="s" placeholder="Search products…" maxlength="100">
            <input type="hidden" name="post_type" value="product">
            <button type="submit" class="search-form__submit" aria-label="Search">{!! $S::icon('search', 20) !!}</button>
        </form>
        <nav aria-label="Mobile">
            <ul class="mnav">
                @foreach ($items as $item)
                    <li class="mnav__item">
                        @if (! empty($item['children']))
                            <details>
                                <summary class="mnav__link">{{ $item['label'] }}{!! $S::icon('chevron-down', 18) !!}</summary>
                                <ul class="mnav__sub">
                                    @if ($item['url'] && $item['url'] !== '#')
                                        <li><a href="{{ $href($item['url']) }}">All {{ $item['label'] }}</a></li>
                                    @endif
                                    @foreach ($item['children'] as $child)
                                        <li><a href="{{ $href($child['url']) }}">{{ $child['label'] }}</a></li>
                                        @foreach ($child['children'] ?? [] as $grand)
                                            <li class="mnav__grand"><a href="{{ $href($grand['url']) }}">{{ $grand['label'] }}</a></li>
                                        @endforeach
                                    @endforeach
                                </ul>
                            </details>
                        @else
                            <a class="mnav__link" href="{{ $href($item['url']) }}" @if ($item['new_tab']) target="_blank" rel="noopener" @endif>{{ $item['label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
        <div class="mnav__footer">
            <a class="btn btn--outline btn--block" href="{{ route('account') }}">{!! $S::icon('user', 18) !!}<span>{{ auth()->check() ? 'My account' : 'Sign in / Register' }}</span></a>
            @if ($store['phone'] !== '')
                <a class="mnav__contact" href="{{ $S::tel($store['phone']) }}">{!! $S::icon('phone', 18) !!}{{ $store['phone'] }}</a>
            @endif
            @if ($store['email'] !== '')
                <a class="mnav__contact" href="mailto:{{ $store['email'] }}">{!! $S::icon('mail', 18) !!}{{ $store['email'] }}</a>
            @endif
        </div>
    </div>
</aside>
