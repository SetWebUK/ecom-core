{{-- Cookie consent (Settings › SEO & tracking › Cookie banner). Stores "store_consent" = comma list of granted categories. --}}
@php
    $mode = (string) setting('tracking.cookie_banner', 'auto');
    $hasTracking = filled(setting('tracking.gtm_id')) || filled(setting('tracking.ga4_id'));
    $show = $mode === 'always' || ($mode === 'auto' && $hasTracking);
@endphp
@if ($show)
    <div class="cookie-banner" role="dialog" aria-live="polite" aria-label="Cookie consent" hidden data-cookie-banner>
        <p>{{ setting('tracking.cookie_text', 'We use cookies to give you the best experience and to understand how our site is used.') }}</p>
        <div class="cookie-banner__actions">
            <button type="button" class="btn btn--outline btn--sm" data-consent="necessary">Necessary only</button>
            <button type="button" class="btn btn--primary btn--sm" data-consent="all">Accept all</button>
        </div>
    </div>
@endif
