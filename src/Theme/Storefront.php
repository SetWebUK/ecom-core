<?php

namespace Pine\Commerce\Theme;

use Pine\Commerce\Services\Cart;

/**
 * View helpers for the default theme (and any theme that wants them): store details, basket count, brand CSS
 * variables from the theme settings, and a small inline SVG icon set. Everything is memoised per request.
 *
 *   @php $S = \Pine\Commerce\Theme\Storefront::class; @endphp
 *   {{ $S::store()['name'] }}  {!! $S::icon('bag') !!}  <style>{!! $S::cssVariables() !!}</style>
 */
class Storefront
{
    protected static ?array $store = null;

    protected static ?int $cartCount = null;

    /** Reset memoised values (tests switch themes/settings within one process). */
    public static function flush(): void
    {
        static::$store = null;
        static::$cartCount = null;
    }

    /** @return array{name:string, company:string, phone:string, email:string, address:string, logo:?string, footer_logo:?string, socials:array<string,string>} */
    public static function store(): array
    {
        if (static::$store !== null) {
            return static::$store;
        }
        $logo = trim((string) theme_setting('logo', '')) ?: trim((string) setting('store.logo', ''));
        $footerLogo = trim((string) setting('store.footer_logo', ''));
        $socials = [];
        foreach (['facebook', 'instagram', 'twitter', 'youtube', 'tiktok'] as $network) {
            $url = trim((string) setting('store.'.$network, ''));
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $socials[$network] = $url;
            }
        }

        return static::$store = [
            'name' => (string) setting('store.name', config('app.name', 'Shop')),
            'company' => (string) setting('store.company_name', ''),
            'phone' => (string) setting('store.phone', ''),
            'email' => (string) setting('store.email', ''),
            'address' => (string) setting('store.address', ''),
            'logo' => $logo !== '' ? media_url($logo) : null,
            'footer_logo' => $footerLogo !== '' ? media_url($footerLogo) : null,
            'socials' => $socials,
        ];
    }

    public static function cartCount(): int
    {
        if (static::$cartCount !== null) {
            return static::$cartCount;
        }
        try {
            return static::$cartCount = (int) app(Cart::class)->count();
        } catch (\Throwable $e) {
            return static::$cartCount = 0;
        }
    }

    /** "tel:" href for a phone number. */
    public static function tel(string $phone): string
    {
        return 'tel:'.preg_replace('/[^0-9+]/', '', $phone);
    }

    // ------------------------------------------------------------------------------------------------ brand CSS

    public const FONTS = [
        'inter' => ['"Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', 'inter-normal-100-900'],
        'manrope' => ['"Manrope", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', 'manrope-normal-200-800'],
        'system' => ['ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif', null],
        'serif' => ['Georgia, Cambria, "Times New Roman", Times, serif', null],
    ];

    public const RADII = ['none' => '0px', 'sm' => '4px', 'md' => '10px', 'lg' => '18px'];

    /** A #rgb / #rrggbb colour from a theme setting, else the fallback. */
    public static function color(string $key, string $fallback): string
    {
        $value = trim((string) theme_setting($key, $fallback));

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? strtolower($value) : $fallback;
    }

    /** Black or white, whichever reads better on $hex (WCAG relative luminance). */
    public static function contrast(string $hex): string
    {
        [$r, $g, $b] = static::rgb($hex);
        $lin = fn ($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);

        return $l > 0.36 ? '#0b0f19' : '#ffffff';
    }

    /** @return array{0:int,1:int,2:int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** Mix $hex with $with (0..1 of $with). */
    public static function mix(string $hex, string $with, float $amount): string
    {
        [$r1, $g1, $b1] = static::rgb($hex);
        [$r2, $g2, $b2] = static::rgb($with);
        $m = fn ($a, $b) => (int) round($a + ($b - $a) * $amount);

        return sprintf('#%02x%02x%02x', $m($r1, $r2), $m($g1, $g2), $m($b1, $b2));
    }

    public static function fontKey(string $setting, string $fallback): string
    {
        $key = (string) theme_setting($setting, $fallback);

        return isset(static::FONTS[$key]) ? $key : $fallback;
    }

    /** @font-face rules for the self-hosted fonts the settings use. */
    public static function fontFaces(): string
    {
        $css = '';
        foreach (array_unique([static::fontKey('font_body', 'inter'), static::fontKey('font_heading', 'manrope')]) as $key) {
            $file = static::FONTS[$key][1];
            if (! $file) {
                continue;
            }
            $family = ucfirst($key);
            $weights = $key === 'inter' ? '100 900' : '200 800';
            foreach (['latin-ext' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF',
                'latin' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'] as $subset => $range) {
                $css .= '@font-face{font-family:"'.$family.'";font-style:normal;font-weight:'.$weights.';font-display:swap;src:url("'.theme_asset('fonts/'.$file.'-'.$subset.'.woff2').'") format("woff2");unicode-range:'.$range.'}';
            }
        }

        return $css;
    }

    /** :root custom properties from Admin › Settings › Theme. */
    public static function cssVariables(): string
    {
        $primary = static::color('primary_color', '#1d4ed8');
        $accent = static::color('accent_color', '#e11d48');
        $text = static::color('text_color', '#0f172a');
        $bg = static::color('background_color', '#ffffff');
        $surface = static::color('surface_color', '#f4f5f7');
        $radius = static::RADII[(string) theme_setting('radius', 'md')] ?? '10px';
        $logoHeight = max(20, min(120, (int) theme_setting('logo_height', 40)));
        $vars = [
            '--c-primary' => $primary,
            '--c-primary-hover' => static::mix($primary, '#000000', 0.14),
            '--c-primary-soft' => static::mix($primary, '#ffffff', 0.9),
            '--c-on-primary' => static::contrast($primary),
            '--c-accent' => $accent,
            '--c-on-accent' => static::contrast($accent),
            '--c-text' => $text,
            '--c-muted' => static::mix($text, $bg, 0.42),
            '--c-border' => static::mix($text, $bg, 0.86),
            '--c-border-strong' => static::mix($text, $bg, 0.72),
            '--c-bg' => $bg,
            '--c-surface' => $surface,
            '--c-surface-2' => static::mix($surface, $text, 0.04),
            '--font-body' => static::FONTS[static::fontKey('font_body', 'inter')][0],
            '--font-heading' => static::FONTS[static::fontKey('font_heading', 'manrope')][0],
            '--radius' => $radius,
            '--radius-lg' => $radius === '0px' ? '0px' : 'calc('.$radius.' * 1.6)',
            '--logo-h' => $logoHeight.'px',
        ];
        $css = ':root{';
        foreach ($vars as $name => $value) {
            $css .= $name.':'.$value.';';
        }

        return $css.'}';
    }

    // ------------------------------------------------------------------------------------------------ icons

    /** Stroke icons (24×24, currentColor) – a subset of Heroicons (MIT) plus brand marks. */
    public const ICONS = [
        'search' => '<path d="m21 21-5.2-5.2M10.5 18a7.5 7.5 0 1 0 0-15 7.5 7.5 0 0 0 0 15Z"/>',
        'user' => '<path d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.1a7.5 7.5 0 0 1 15 0A17.9 17.9 0 0 1 12 21.75c-2.68 0-5.22-.58-7.5-1.63Z"/>',
        'bag' => '<path d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.36-1.99 1.26 12c.07.66-.45 1.24-1.12 1.24H4.25a1.13 1.13 0 0 1-1.12-1.24l1.26-12A1.13 1.13 0 0 1 5.51 7.5h12.98c.58 0 1.06.44 1.12 1.01ZM8.63 10.5a.38.38 0 1 1-.75 0 .38.38 0 0 1 .75 0Zm7.5 0a.38.38 0 1 1-.75 0 .38.38 0 0 1 .75 0Z"/>',
        'heart' => '<path d="M21 8.25c0-2.49-2.1-4.5-4.69-4.5-1.93 0-3.6 1.13-4.31 2.73-.72-1.6-2.38-2.73-4.31-2.73C5.1 3.75 3 5.76 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/>',
        'menu' => '<path d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>',
        'close' => '<path d="M6 18 18 6M6 6l12 12"/>',
        'chevron-down' => '<path d="m19.5 8.25-7.5 7.5-7.5-7.5"/>',
        'chevron-right' => '<path d="m8.25 4.5 7.5 7.5-7.5 7.5"/>',
        'chevron-left' => '<path d="M15.75 19.5 8.25 12l7.5-7.5"/>',
        'arrow-right' => '<path d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>',
        'plus' => '<path d="M12 4.5v15m7.5-7.5h-15"/>',
        'minus' => '<path d="M19.5 12h-15"/>',
        'trash' => '<path d="m14.74 9-.35 9m-4.78 0L9.26 9m9.97-3.21c.34.05.68.11 1.02.17m-1.02-.17-1.07 13.88a2.25 2.25 0 0 1-2.24 2.08H8.08a2.25 2.25 0 0 1-2.24-2.08L4.77 5.79m14.46 0a48.1 48.1 0 0 0-3.48-.4m-12 .57c.34-.06.68-.12 1.02-.17m0 0a48.1 48.1 0 0 1 3.48-.4m7.5 0v-.92c0-1.18-.91-2.16-2.09-2.2a52 52 0 0 0-3.32 0c-1.18.04-2.09 1.02-2.09 2.2v.92m7.5 0a48.7 48.7 0 0 0-7.5 0"/>',
        'check' => '<path d="m4.5 12.75 6 6 9-13.5"/>',
        'check-circle' => '<path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
        'truck' => '<path d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.38a1.13 1.13 0 0 1-1.13-1.13V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.13c.62 0 1.13-.5 1.12-1.13a17.9 17.9 0 0 0-3.21-9.57 1.9 1.9 0 0 0-1.53-.8H14.25M16.5 18.75h-2.25m0-11.18v-.96c0-.57-.42-1.05-.99-1.11a59.8 59.8 0 0 0-10.02 0c-.57.06-.99.54-.99 1.11v7.64m12 0V7.57m0 6.68H2.25"/>',
        'shield' => '<path d="M9 12.75 11.25 15 15 9.75m-3-7.04A11.96 11.96 0 0 1 3.6 6 12 12 0 0 0 3 9.75c0 5.59 3.82 10.29 9 11.62 5.18-1.33 9-6.03 9-11.62 0-1.31-.21-2.57-.6-3.75h-.15c-3.2 0-6.1-1.25-8.25-3.29Z"/>',
        'refresh' => '<path d="M16.02 9.35h4.99v0M2.99 19.64v-5m0 0h5m-5 0 3.18 3.18a8.25 8.25 0 0 0 13.8-3.7M4.03 9.87a8.25 8.25 0 0 1 13.8-3.7l3.18 3.18m0-4.99v4.99"/>',
        'lock' => '<path d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>',
        'phone' => '<path d="M2.25 6.75c0 8.28 6.72 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.37c0-.52-.35-.97-.85-1.09l-4.42-1.1c-.44-.11-.9.05-1.17.41l-.97 1.29c-.28.38-.77.54-1.21.38a12.04 12.04 0 0 1-7.14-7.14c-.16-.44 0-.93.38-1.21l1.29-.97c.36-.27.52-.73.41-1.17l-1.1-4.42a1.13 1.13 0 0 0-1.09-.85H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/>',
        'mail' => '<path d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.24a2.25 2.25 0 0 1-1.07 1.92l-7.5 4.61a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.92V6.75"/>',
        'map-pin' => '<path d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path d="M19.5 10.5c0 7.14-7.5 11.25-7.5 11.25S4.5 17.64 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>',
        'filter' => '<path d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/>',
        'eye' => '<path d="M2.04 12.32a1 1 0 0 1 0-.64C3.42 7.51 7.36 4.5 12 4.5c4.64 0 8.57 3.01 9.96 7.18.07.21.07.43 0 .64C20.58 16.49 16.64 19.5 12 19.5c-4.64 0-8.57-3.01-9.96-7.18Z"/><path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
        'star' => '<path d="M11.48 3.5a.56.56 0 0 1 1.04 0l2.13 5.11c.08.2.27.33.48.35l5.52.44c.5.04.7.66.32.99l-4.2 3.6a.56.56 0 0 0-.18.56l1.28 5.38a.56.56 0 0 1-.84.61l-4.72-2.88a.56.56 0 0 0-.59 0l-4.72 2.88a.56.56 0 0 1-.84-.61l1.28-5.38a.56.56 0 0 0-.18-.56l-4.2-3.6a.56.56 0 0 1 .32-.99l5.52-.44c.21-.02.4-.15.48-.35L11.48 3.5Z"/>',
        'chat' => '<path d="M8.63 12a.38.38 0 1 1-.75 0 .38.38 0 0 1 .75 0Zm4.12 0a.38.38 0 1 1-.75 0 .38.38 0 0 1 .75 0Zm4.13 0a.38.38 0 1 1-.76 0 .38.38 0 0 1 .76 0Z"/><path d="M2.25 12.76c0 1.6 1.12 2.99 2.7 3.23 1.09.16 2.19.28 3.3.37V21l4.08-4.08c.2-.2.47-.31.75-.32a48.5 48.5 0 0 0 5.97-.61c1.58-.23 2.7-1.63 2.7-3.23V6.74c0-1.6-1.12-2.99-2.7-3.23A48.4 48.4 0 0 0 12 3c-2.39 0-4.73.17-7.03.51-1.58.24-2.7 1.63-2.7 3.23v6.02Z"/>',
        'tag' => '<path d="M9.57 3H5.25A2.25 2.25 0 0 0 3 5.25v4.32c0 .6.24 1.17.66 1.59l9.58 9.58c.7.7 1.78.87 2.61.33a18.1 18.1 0 0 0 5.22-5.22c.54-.83.37-1.91-.33-2.61L11.16 3.66A2.25 2.25 0 0 0 9.57 3Z"/><path d="M6 6h.01v.01H6V6Z"/>',
        'grid' => '<path d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6Zm0 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6Zm0 9.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/>',
        'logout' => '<path d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>',
        'home' => '<path d="m2.25 12 8.95-8.95c.44-.44 1.15-.44 1.6 0L21.75 12M4.5 9.75v10.13c0 .62.5 1.12 1.13 1.12H9.75v-4.88c0-.62.5-1.12 1.13-1.12h2.25c.62 0 1.12.5 1.12 1.12V21h4.13c.62 0 1.12-.5 1.12-1.12V9.75M8.25 21h8.25"/>',
        'download' => '<path d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>',
        'document' => '<path d="M19.5 14.25v-2.63a3.38 3.38 0 0 0-3.38-3.37h-1.5A1.13 1.13 0 0 1 13.5 7.13v-1.5a3.38 3.38 0 0 0-3.38-3.38H8.25m2.25 0H5.63c-.63 0-1.13.5-1.13 1.13v17.25c0 .62.5 1.12 1.13 1.12h12.75c.62 0 1.12-.5 1.12-1.12V11.25a9 9 0 0 0-9-9Z"/>',
    ];

    public const BRANDS = [
        'facebook' => '<path fill="currentColor" stroke="none" d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.9 3.78-3.9 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12Z"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>',
        'twitter' => '<path fill="currentColor" stroke="none" d="M17.75 3h3.07l-6.72 7.68L22 21h-6.19l-4.84-6.33L5.43 21H2.36l7.19-8.21L2 3h6.35l4.38 5.79L17.75 3Zm-1.08 16.2h1.7L7.4 4.73H5.58L16.67 19.2Z"/>',
        'youtube' => '<path fill="currentColor" stroke="none" d="M21.58 7.19a2.5 2.5 0 0 0-1.76-1.77C18.25 5 12 5 12 5s-6.25 0-7.82.42A2.5 2.5 0 0 0 2.42 7.2C2 8.76 2 12 2 12s0 3.24.42 4.81a2.5 2.5 0 0 0 1.76 1.77C5.75 19 12 19 12 19s6.25 0 7.82-.42a2.5 2.5 0 0 0 1.76-1.77C22 15.24 22 12 22 12s0-3.24-.42-4.81ZM10 15V9l5.2 3L10 15Z"/>',
        'tiktok' => '<path fill="currentColor" stroke="none" d="M16.6 5.82A4.28 4.28 0 0 1 15.54 3h-3.09v12.4a2.59 2.59 0 0 1-2.59 2.5 2.6 2.6 0 0 1-2.59-2.6 2.6 2.6 0 0 1 3.36-2.48V9.66a5.67 5.67 0 0 0-6.45 5.64A5.7 5.7 0 0 0 9.86 21a5.69 5.69 0 0 0 5.68-5.7V9.01a7.33 7.33 0 0 0 4.29 1.38V7.3s-1.88.09-3.23-1.48Z"/>',
    ];

    public static function icon(string $name, int $size = 22, string $class = ''): string
    {
        $paths = static::ICONS[$name] ?? static::BRANDS[$name] ?? '';

        return '<svg class="icon'.($class !== '' ? ' '.$class : '').'" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$paths.'</svg>';
    }

    /** Payment method marks for the footer / checkout (inline SVG, no external requests). */
    public static function paymentIcons(): string
    {
        $card = fn (string $inner, string $label, string $bg = '#fff') => '<svg class="pay-icon" viewBox="0 0 38 24" width="38" height="24" role="img" aria-label="'.$label.'"><rect x=".5" y=".5" width="37" height="23" rx="3.5" fill="'.$bg.'" stroke="#d9dce1"/>'.$inner.'</svg>';

        return '<span class="pay-icons">'
            .$card('<text x="19" y="16" font-family="Arial,Helvetica,sans-serif" font-size="9.5" font-weight="700" font-style="italic" fill="#1a1f71" text-anchor="middle">VISA</text>', 'Visa')
            .$card('<circle cx="15" cy="12" r="6.5" fill="#eb001b"/><circle cx="23" cy="12" r="6.5" fill="#f79e1b"/><path d="M19 7.2a6.5 6.5 0 0 0 0 9.6 6.5 6.5 0 0 0 0-9.6Z" fill="#ff5f00"/>', 'Mastercard')
            .$card('<text x="19" y="15" font-family="Arial,Helvetica,sans-serif" font-size="7" font-weight="700" fill="#fff" text-anchor="middle">AMEX</text>', 'American Express', '#006fcf')
            .$card('<text x="19" y="15.5" font-family="Arial,Helvetica,sans-serif" font-size="8" font-weight="700" font-style="italic" text-anchor="middle"><tspan fill="#003087">Pay</tspan><tspan fill="#009cde">Pal</tspan></text>', 'PayPal')
            .$card('<text x="19" y="15.5" font-family="-apple-system,Helvetica,Arial,sans-serif" font-size="6.6" font-weight="700" fill="#000" text-anchor="middle">Apple Pay</text>', 'Apple Pay')
            .$card('<text x="19" y="15.5" font-family="Arial,Helvetica,sans-serif" font-size="8.5" font-weight="600" text-anchor="middle"><tspan fill="#4285f4">G</tspan><tspan fill="#5f6368"> Pay</tspan></text>', 'Google Pay')
            .'</span>';
    }
}
