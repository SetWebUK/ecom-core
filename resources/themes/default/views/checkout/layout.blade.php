{{-- Checkout / order-received / order-pay layout: the store layout with a slim header and no footer menus. --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $store = $S::store();
    $locale = str_replace('_', '-', (string) config('commerce.store.locale', 'en_GB'));
    $favicon = setting('store.favicon');
    $childCss = [];
    foreach (array_reverse(theme()->lineage()) as $t) {
        if ($t->slug !== 'default' && is_file(public_path($t->publicPath().'/css/theme.css'))) {
            $childCss[] = asset($t->publicPath().'/css/theme.css').'?v='.filemtime(public_path($t->publicPath().'/css/theme.css'));
        }
    }
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Checkout | '.$store['name'])</title>
    @if ($favicon)<link rel="icon" href="{{ media_url($favicon) }}">@endif
    <style>{!! $S::fontFaces() !!}</style>
    <link rel="stylesheet" href="{{ theme_asset('css/app.css') }}">
    <style>{!! $S::cssVariables() !!}</style>
    @foreach ($childCss as $href)<link rel="stylesheet" href="{{ $href }}">@endforeach
    <script>window.dataLayer = window.dataLayer || [];</script>
    @stack('head')
</head>
<body class="theme-{{ theme()->slug }} layout-checkout @yield('body_class')">
<header class="checkout-header">
    <div class="container checkout-header__inner">
        <a class="site-logo" href="{{ url('/') }}/">
            @if ($store['logo'])<img src="{{ $store['logo'] }}" alt="{{ $store['name'] }}">@else<span class="site-logo__text">{{ $store['name'] }}</span>@endif
        </a>
        <p class="checkout-header__secure">{!! $S::icon('lock', 18) !!}<span>Secure checkout</span></p>
    </div>
</header>
<main id="main" class="checkout-main">
    @yield('content')
</main>
<footer class="checkout-footer">
    <div class="container checkout-footer__inner">
        <p>© {{ date('Y') }} {{ $store['company'] ?: $store['name'] }}</p>
        @if ($store['phone'] !== '')<p>Need help? <a href="{{ $S::tel($store['phone']) }}">{{ $store['phone'] }}</a></p>@endif
        {!! $S::paymentIcons() !!}
    </div>
</footer>
<div class="toasts" data-toasts aria-live="polite"></div>
<script src="{{ theme_asset('js/checkout.js') }}" defer></script>
@stack('scripts')
</body>
</html>
