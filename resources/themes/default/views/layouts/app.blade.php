{{--
    Default theme – storefront layout.
      @section('title') / @section('meta_description') / @section('canonical') / @section('robots')  (or a $seo array)
      @section('body_class')   extra body classes
      @section('content')      page body, rendered inside <main id="main">
      @push('head') / @push('scripts')
    Brand colours, fonts and the logo come from Admin › Settings › Theme (theme_setting()) as CSS custom properties.
    A child theme's assets/css/theme.css is loaded automatically after css/app.css.
    Global JS (js/app.js): window.Store.cart.open()/refresh(), document event "store:cart-updated" (detail.count).
--}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $store = $S::store();
    $cartCount = $S::cartCount();
    $favicon = setting('store.favicon');
    $faviconUrl = $favicon ? media_url($favicon) : null;
    $childCss = [];
    foreach (array_reverse(theme()->lineage()) as $t) {
        if ($t->slug !== 'default' && is_file(public_path($t->publicPath().'/css/theme.css'))) {
            $childCss[] = asset($t->publicPath().'/css/theme.css').'?v='.filemtime(public_path($t->publicPath().'/css/theme.css'));
        }
    }
    $isCheckout = request()->routeIs('checkout', 'checkout.*');
    $locale = str_replace('_', '-', (string) config('commerce.store.locale', 'en_GB'));
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.seo')
    @if (commerce_feature('blog'))
        <link rel="alternate" type="application/rss+xml" title="{{ $store['name'] }} &raquo; Feed" href="{{ route('feed.posts') }}">
    @endif
    @yield('meta')
    @if ($faviconUrl)
        <link rel="icon" href="{{ $faviconUrl }}">
        <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    @endif
    <style>{!! $S::fontFaces() !!}</style>
    <link rel="stylesheet" href="{{ theme_asset('css/app.css') }}">
    {{-- Admin › Settings › Theme values must come after app.css (which declares the defaults) and before child theme CSS --}}
    <style>{!! $S::cssVariables() !!}</style>
    @foreach ($childCss as $href)
        <link rel="stylesheet" href="{{ $href }}">
    @endforeach
    @include('partials.tracking')
    @stack('head')
</head>
<body class="theme-{{ theme()->slug }} header-{{ theme_setting('header_style', 'light') === 'brand' ? 'brand' : 'light' }} @yield('body_class')">
@include('partials.tracking', ['part' => 'body'])
<a class="skip-link" href="#main">Skip to content</a>

@include('partials.header')

<main id="main" class="site-main" tabindex="-1">
    @yield('content')
</main>

@include('partials.footer')

@unless ($isCheckout)
    {{-- Side basket: filled from GET /cart/fragment (js/app.js) --}}
    <div class="drawer-overlay" data-drawer-overlay hidden></div>
    <aside class="drawer drawer--right side-cart" id="side-cart" role="dialog" aria-modal="true" aria-labelledby="side-cart-title" aria-hidden="true" tabindex="-1"
           data-side-cart data-fragment-url="{{ route('cart.fragment') }}">
        <div class="drawer__head">
            <h2 class="drawer__title" id="side-cart-title">Your basket</h2>
            <button type="button" class="icon-btn" aria-label="Close basket" data-drawer-close>{!! $S::icon('close') !!}</button>
        </div>
        <div class="drawer__body" data-side-cart-content aria-live="polite">
            <div class="side-cart__loading"><span class="spinner" aria-hidden="true"></span><span class="sr-only">Loading your basket…</span></div>
        </div>
    </aside>
@endunless

@if (commerce_feature('quick_view'))
    <dialog class="modal quick-view" id="quick-view" aria-label="Quick view">
        <button type="button" class="icon-btn modal__close" aria-label="Close" data-modal-close>{!! $S::icon('close') !!}</button>
        <div class="modal__body" data-quick-view-content></div>
    </dialog>
@endif

@include('partials.cookie-banner')

<div class="toasts" data-toasts aria-live="polite" aria-atomic="false"></div>

<script type="application/json" id="store-config">{!! json_encode([
    'cartUrl' => route('cart.add'),
    'cartUpdateUrl' => route('cart.update'),
    'cartRemoveUrl' => route('cart.remove'),
    'fragmentUrl' => route('cart.fragment'),
    'wishlistUrl' => commerce_feature('wishlist') ? route('wishlist.toggle') : null,
    'searchUrl' => route('search.suggest'),
    'catalogHeader' => config('commerce.catalog.ajax_header', 'X-Commerce-Catalog'),
    'openCartCookie' => \Pine\Commerce\Http\Controllers\CartController::openCookie(),
    'currency' => config('commerce.currency.symbol', '£'),
    'attributionCookie' => \Pine\Commerce\Services\Checkout\Attribution::cookieName(),
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="{{ theme_asset('js/app.js') }}" defer></script>
@stack('scripts')
</body>
</html>
