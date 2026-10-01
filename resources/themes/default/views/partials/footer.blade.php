{{-- Site footer: newsletter, about + contact, up to three menu columns, socials, payment icons, legal line. --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $store = $S::store();
    $href = fn ($url) => \Pine\Commerce\View\Components\MenuComponent::href($url) ?? '#';
    $columns = [];
    foreach ((array) theme_config('menus.footer', []) as $location) {
        $items = menu_tree($location);
        if ($items && count($columns) < 3) {
            $name = trim((string) preg_replace('/^footer\s*[\x{2013}\x{2014}:\-]\s*/iu', '', (string) \Pine\Commerce\View\Components\MenuComponent::nameFor($location)));
            $columns[] = ['title' => $name !== '' ? $name : 'Links', 'items' => $items, 'location' => $location];
        }
    }
    $legal = menu_tree(theme_config('menus.legal', ['footer_legal']));
    $about = trim((string) setting('store.footer_about', ''));
    $company = $store['company'] ?: $store['name'];
    $legalLine = trim((string) setting('store.legal_line', ''));
    if ($legalLine === '') {
        $legalLine = collect([
            $store['company'],
            ($n = trim((string) setting('store.company_number', ''))) !== '' ? 'Company no. '.$n : null,
            ($v = trim((string) setting('store.vat_number', ''))) !== '' ? 'VAT no. '.$v : null,
        ])->filter()->implode(' · ');
    }
    $copyright = trim((string) setting('store.copyright', ''));
    $copyright = $copyright !== '' ? preg_replace('/\b(19|20)\d{2}\b/', date('Y'), $copyright, 1) : '© '.date('Y').' '.$company.'. All rights reserved.';
    $showNewsletter = commerce_feature('newsletter') && filter_var(theme_setting('show_newsletter', true), FILTER_VALIDATE_BOOL);
@endphp
<footer class="site-footer">
    @if ($showNewsletter)
        @include('partials.newsletter')
    @endif
    <div class="container site-footer__grid">
        <div class="site-footer__about">
            <a class="site-logo site-logo--footer" href="{{ url('/') }}/">
                @if ($store['footer_logo'] ?? $store['logo'])
                    <img src="{{ $store['footer_logo'] ?? $store['logo'] }}" alt="{{ $store['name'] }}" loading="lazy">
                @else
                    <span class="site-logo__text">{{ $store['name'] }}</span>
                @endif
            </a>
            @if ($about !== '')
                <p class="site-footer__text">{{ $about }}</p>
            @endif
            <ul class="contact-list">
                @if ($store['phone'] !== '')
                    <li>{!! $S::icon('phone', 18) !!}<a href="{{ $S::tel($store['phone']) }}">{{ $store['phone'] }}</a></li>
                @endif
                @if ($store['email'] !== '')
                    <li>{!! $S::icon('mail', 18) !!}<a href="mailto:{{ $store['email'] }}">{{ $store['email'] }}</a></li>
                @endif
                @if ($store['address'] !== '')
                    <li>{!! $S::icon('map-pin', 18) !!}<span>{!! nl2br(e($store['address'])) !!}</span></li>
                @endif
            </ul>
            @if ($store['socials'])
                <ul class="socials" aria-label="Follow us">
                    @foreach ($store['socials'] as $network => $url)
                        <li><a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ ucfirst($network === 'twitter' ? 'X' : $network) }}">{!! $S::icon($network, 20) !!}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>
        @foreach ($columns as $column)
            @include('partials.footer-menu', ['items' => $column['items'], 'location' => $column['location'], 'variant' => 'column', 'title' => $column['title']])
        @endforeach
    </div>
    <div class="site-footer__bottom">
        <div class="container site-footer__bottom-inner">
            <div class="site-footer__legal">
                <p>{{ $copyright }}</p>
                @if ($legalLine !== '')
                    <p class="site-footer__small">{{ $legalLine }}</p>
                @endif
                @if ($legal)
                    @include('partials.footer-menu', ['items' => $legal, 'location' => 'footer_legal', 'variant' => 'row'])
                @endif
            </div>
            {!! $S::paymentIcons() !!}
        </div>
    </div>
</footer>
