{{--
    Tracking (Admin › Settings › SEO & tracking): Google Consent Mode defaults, GA4 / Google Ads (gtag.js), Tag Manager, the
    Meta Pixel and the shop's events. Used by layouts.app on every page and by checkout.layout on the order-received page
    only: the checkout and order-pay pages, where card details are entered, load no third-party scripts.
      @include('partials.tracking')                      in <head>
      @include('partials.tracking', ['part' => 'body'])  first thing in <body> (Tag Manager's noscript frame)

    Shop events, as WooCommerce's Google Analytics, Pixel Manager and GTM Kit plugins sent them (Services\Marketing\
    Ecommerce, set by the controllers): view_item_list, view_item, add_to_cart (app.js's store:added-to-cart),
    begin_checkout (a click on a link to the checkout), purchase (once per order). Each goes to
      - Tag Manager's dataLayer (GA4 ecommerce format: {event, ecommerce: {currency, value, items}})
      - GA4 (item_id = SKU, else "#" + product ID), when a GA4 ID is set
      - the Google Ads accounts, with "Google Ads dynamic remarketing" (items: the shopping feed's IDs, retail)
      - the Meta Pixel (ViewContent, AddToCart, InitiateCheckout, Purchase; content_ids = the shopping feed's IDs)
    and as the document event store:track ({event, data, ecommerce}) for a theme's own tags. A theme can add tags with
    partials.tracking-page (optional): $tracking (Ecommerce::page()), $trackingOrder (the paid order, or null),
    $adsAccounts (["AW-…"]); it is included before the events are sent, so its store:track listeners see them all.
    With Tag Manager on the page too, the shop's gtag.js keeps its own queue (storeGtagLayer, as WooCommerce's
    plugins kept dataLayerPYS), so neither library reads the other's commands and nothing is sent twice; consent
    and "set" commands go to both. Shop code and themes send through window.storeGtag().
--}}
@php
    $gtmId = strtoupper(trim((string) setting('tracking.gtm_id', '')));
    $gtmId = preg_match('/^GTM-[A-Z0-9]+$/', $gtmId) ? $gtmId : null;
    $ga4Id = strtoupper(trim((string) setting('tracking.ga4_id', '')));
    $ga4Id = preg_match('/^G-[A-Z0-9]+$/', $ga4Id) ? $ga4Id : null;
    $pixelId = preg_match('/^\d{8,20}$/', $p = trim((string) setting('tracking.meta_pixel_id', ''))) ? $p : null;
    // Google Ads purchase conversions ("AW-1/a, AW-2/b"): each account gets the tag and counts the sale
    preg_match_all('/(AW-\d+)\/[A-Za-z0-9_-]+/', (string) setting('tracking.google_ads_conversion', ''), $m);
    $conversions = array_values(array_unique($m[0]));
    $adsAccounts = array_values(array_unique($m[1]));
    $gtagIds = array_values(array_unique(array_filter([$ga4Id, ...$adsAccounts])));
    $ga4PageViews = filter_var(setting('tracking.ga4_page_views', true), FILTER_VALIDATE_BOOL);
    $remarketing = $adsAccounts && filter_var(setting('tracking.google_ads_remarketing', false), FILTER_VALIDATE_BOOL);
    $ownLayer = $gtmId && $gtagIds;
    $json = fn ($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
@endphp
@if (($part ?? 'head') === 'body')
    @if ($gtmId)
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $gtmId }}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    @endif
@else
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        @if ($ownLayer)
        window.storeGtagLayer = window.storeGtagLayer || [];
        window.storeGtag = function(){storeGtagLayer.push(arguments);};
        gtag = window.gtag = (function (base) { return function () { base.apply(window, arguments); if (arguments[0] === 'consent' || arguments[0] === 'set') { storeGtagLayer.push(arguments); } }; })(gtag);
        @else
        window.storeGtag = gtag;
        @endif
        gtag('consent', 'default', {ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', personalization_storage: 'denied', functionality_storage: 'granted', security_storage: 'granted', wait_for_update: 500});
        (function () {
            try {
                var m = document.cookie.match(/(?:^|; )store_consent=([^;]*)/);
                if (!m) { return; }
                var c = decodeURIComponent(m[1]).split(','), has = function (k) { return c.indexOf(k) > -1 ? 'granted' : 'denied'; };
                gtag('consent', 'update', {analytics_storage: has('statistics'), ad_storage: has('marketing'), ad_user_data: has('marketing'), ad_personalization: has('marketing'), personalization_storage: has('preferences')});
            } catch (e) {}
        })();
        @if ($adsAccounts)
        gtag('set', 'url_passthrough', true);
        @endif
    </script>
    @if ($gtagIds)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gtagIds[0] }}{{ $ownLayer ? '&l=storeGtagLayer' : '' }}"></script>
        <script>storeGtag('js', new Date()); {!! implode(' ', array_map(fn ($id) => $id === $ga4Id && ! $ga4PageViews ? "storeGtag('config', '{$id}', {send_page_view: false});" : "storeGtag('config', '{$id}');", $gtagIds)) !!}</script>
    @endif
    @if ($gtmId)
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{{ $gtmId }}');</script>
    @endif
    @if ($pixelId)
        {{-- Meta Pixel: only after the visitor accepts marketing cookies (on load, or from the cookie banner, which calls
             storeStartMarketing; then the product being viewed is sent too). --}}
        <script>
            (function (w, d) {
                var started = false;
                w.storeStartMarketing = function () {
                    if (started) { return; }
                    started = true;
                    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(w,d,'script','https://connect.facebook.net/en_US/fbevents.js');
                    fbq('init', '{{ $pixelId }}');
                    fbq('track', 'PageView');
                    if (w.storeTrack) { w.storeTrack.replayForMeta(); }
                };
                try {
                    var m = d.cookie.match(/(?:^|; )store_consent=([^;]*)/);
                    if (m && decodeURIComponent(m[1]).split(',').indexOf('marketing') > -1) { w.storeStartMarketing(); }
                } catch (e) {}
            })(window, document);
        </script>
    @endif
    @if ($gtmId || $gtagIds || $pixelId)
        @php($tracking = app(\Pine\Commerce\Services\Marketing\Ecommerce::class)->page())
        <script>window.storeTracking = {!! $json($tracking) !!};</script>
        @includeIf('partials.tracking-page', ['tracking' => $tracking, 'trackingOrder' => app(\Pine\Commerce\Services\Marketing\Ecommerce::class)->order(), 'adsAccounts' => $adsAccounts])
        <script>
            (function (w, d) {
                var T = w.storeTracking || {}, ga4 = {!! $json($ga4Id) !!}, ads = {!! $json($remarketing ? $adsAccounts : []) !!}, conversions = {!! $json($tracking['page'] === 'purchase' ? $conversions : []) !!};
                var checkoutPath = {!! $json(rtrim((string) parse_url(url('checkout'), PHP_URL_PATH), '/')) !!};
                var basket = null;
                var gaKeys = ['item_id', 'item_name', 'item_category', 'item_variant', 'price', 'quantity', 'index', 'item_list_name'];
                function gaItems(items) {
                    return (items || []).map(function (i) { var o = {}; gaKeys.forEach(function (k) { if (i[k] !== undefined && i[k] !== null) { o[k] = i[k]; } }); return o; });
                }
                function ids(items) { return (items || []).map(function (i) { return i.id; }); }
                function count(items) { return (items || []).reduce(function (n, i) { return n + (i.quantity || 1); }, 0); }
                function meta(event, data) {
                    if (typeof w.fbq !== 'function') { return; }
                    var p = { content_type: 'product', content_ids: ids(data.items), currency: T.currency };
                    if (typeof data.value === 'number') { p.value = data.value; }
                    if (event === 'view_item') { p.content_name = data.items && data.items[0] ? data.items[0].item_name : undefined; fbq('track', 'ViewContent', p); }
                    else if (event === 'add_to_cart') { fbq('track', 'AddToCart', p); }
                    else if (event === 'begin_checkout') { p.num_items = count(data.items); fbq('track', 'InitiateCheckout', p); }
                    else if (event === 'purchase') { p.num_items = count(data.items); fbq('track', 'Purchase', p, { eventID: 'order-' + T.transaction_id }); }
                }
                function send(event, data, extra) {
                    data = data || {};
                    var eco = { currency: T.currency }, k;
                    if (typeof data.value === 'number') { eco.value = data.value; }
                    for (k in (extra || {})) { if (extra[k] !== undefined && extra[k] !== '') { eco[k] = extra[k]; } }
                    eco.items = gaItems(data.items);
                    w.dataLayer = w.dataLayer || [];
                    w.dataLayer.push({ ecommerce: null });
                    w.dataLayer.push({ event: event, ecommerce: eco });
                    if (ga4) {
                        var g = { send_to: ga4 };
                        for (k in eco) { g[k] = eco[k]; }
                        storeGtag('event', event, g);
                    }
                    if (ads.length && data.items && data.items.length) {
                        storeGtag('event', event, { send_to: ads, value: eco.value, currency: T.currency, items: data.items.map(function (i) { return { id: i.id, google_business_vertical: 'retail' }; }) });
                    }
                    meta(event, data);
                    try { d.dispatchEvent(new CustomEvent('store:track', { detail: { event: event, data: data, ecommerce: eco } })); } catch (e) {}
                }
                w.storeTrack = {
                    send: send,
                    replayForMeta: function () { if (T.page === 'product') { meta('view_item', T); } }
                };

                if (T.page === 'product') {
                    send('view_item', T);
                } else if (T.page === 'list' && T.items && T.items.length) {
                    send('view_item_list', T, { item_list_name: T.list });
                } else if (T.page === 'purchase') {
                    var done = false;
                    try { done = !!(w.localStorage && localStorage.getItem(T.once)); } catch (e) {}
                    if (!done) {
                        send('purchase', T, { transaction_id: T.transaction_id, tax: T.tax, shipping: T.shipping, coupon: T.coupon });
                        conversions.forEach(function (c) { storeGtag('event', 'conversion', { send_to: c, value: T.value, currency: T.currency, transaction_id: T.transaction_id }); });
                        try { localStorage.setItem(T.once, '1'); } catch (e) {}
                    }
                }
                d.addEventListener('store:cart-updated', function (e) { if (e.detail && e.detail.tracking_cart) { basket = e.detail.tracking_cart; } });
                d.addEventListener('store:added-to-cart', function (e) {
                    var t = e.detail && e.detail.tracking;
                    if (t && t.items) { send('add_to_cart', t); }
                });
                d.addEventListener('click', function (e) {
                    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
                    if (!a) { return; }
                    var u;
                    try { u = new URL(a.getAttribute('href'), w.location.href); } catch (x) { return; }
                    if (u.origin !== w.location.origin || u.pathname.replace(/\/+$/, '') !== checkoutPath) { return; }
                    send('begin_checkout', basket || {});
                });
            })(window, document);
        </script>
    @endif
@endif
