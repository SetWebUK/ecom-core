{{--
    Tracking (Admin › Settings › SEO & tracking): Google Consent Mode defaults, GA4 / Google Ads (gtag.js), Tag Manager and
    the Meta Pixel. Used by layouts.app on every page and by checkout.layout on the order-received page only: the checkout
    and order-pay pages, where card details are entered, load no third-party scripts.
      @include('partials.tracking')                      in <head>
      @include('partials.tracking', ['part' => 'body'])  first thing in <body> (Tag Manager's noscript frame)
--}}
@php
    $gtmId = strtoupper(trim((string) setting('tracking.gtm_id', '')));
    $gtmId = preg_match('/^GTM-[A-Z0-9]+$/', $gtmId) ? $gtmId : null;
    $ga4Id = strtoupper(trim((string) setting('tracking.ga4_id', '')));
    $ga4Id = preg_match('/^G-[A-Z0-9]+$/', $ga4Id) ? $ga4Id : null;
    $pixelId = preg_match('/^\d{8,20}$/', $p = trim((string) setting('tracking.meta_pixel_id', ''))) ? $p : null;
    $adsId = preg_match('/^(AW-\d+)\/[A-Za-z0-9_-]+$/', trim((string) setting('tracking.google_ads_conversion', '')), $m) ? $m[1] : null;
    $gtagIds = array_values(array_filter([$ga4Id, $adsId]));
@endphp
@if (($part ?? 'head') === 'body')
    @if ($gtmId)
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $gtmId }}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    @endif
@else
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('consent', 'default', {ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', personalization_storage: 'denied', functionality_storage: 'granted', security_storage: 'granted', wait_for_update: 500});
        (function () {
            try {
                var m = document.cookie.match(/(?:^|; )store_consent=([^;]*)/);
                if (!m) { return; }
                var c = decodeURIComponent(m[1]).split(','), has = function (k) { return c.indexOf(k) > -1 ? 'granted' : 'denied'; };
                gtag('consent', 'update', {analytics_storage: has('statistics'), ad_storage: has('marketing'), ad_user_data: has('marketing'), ad_personalization: has('marketing'), personalization_storage: has('preferences')});
            } catch (e) {}
        })();
    </script>
    @if ($gtagIds)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gtagIds[0] }}"></script>
        <script>gtag('js', new Date()); {!! implode(' ', array_map(fn ($id) => "gtag('config', '{$id}');", $gtagIds)) !!}</script>
    @endif
    @if ($gtmId)
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{{ $gtmId }}');</script>
    @endif
    @if ($pixelId)
        {{-- Meta Pixel: only after the visitor accepts marketing cookies (on load, or from the cookie banner, which calls
             storeStartMarketing). Product views come from the page's Product JSON-LD, add to basket from app.js's
             store:added-to-cart event, the purchase from checkout/thankyou. --}}
        <script>
            (function (w, d) {
                var started = false;
                w.storeStartMarketing = function () {
                    if (started) { return; }
                    started = true;
                    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(w,d,'script','https://connect.facebook.net/en_US/fbevents.js');
                    fbq('init', '{{ $pixelId }}');
                    fbq('track', 'PageView');
                    var product = null;
                    var view = function () {
                        var scripts = d.querySelectorAll('script[type="application/ld+json"]');
                        for (var i = 0; i < scripts.length && !product; i++) {
                            try { var j = JSON.parse(scripts[i].textContent); if (j && j['@type'] === 'Product') { product = j; } } catch (e) {}
                        }
                        if (!product) { return; }
                        var data = { content_type: 'product', content_name: product.name };
                        if (product.sku) { data.content_ids = [product.sku]; }
                        if (product.offers) { data.value = Number(product.offers.price); data.currency = product.offers.priceCurrency; }
                        fbq('track', 'ViewContent', data);
                    };
                    d.readyState === 'loading' ? d.addEventListener('DOMContentLoaded', view) : view();
                    d.addEventListener('store:added-to-cart', function () {
                        fbq('track', 'AddToCart', product && product.sku ? { content_type: 'product', content_ids: [product.sku] } : {});
                    });
                };
                try {
                    var m = d.cookie.match(/(?:^|; )store_consent=([^;]*)/);
                    if (m && decodeURIComponent(m[1]).split(',').indexOf('marketing') > -1) { w.storeStartMarketing(); }
                } catch (e) {}
            })(window, document);
        </script>
    @endif
@endif
