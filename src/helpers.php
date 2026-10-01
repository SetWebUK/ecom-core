<?php

use Pine\Commerce\Models\Setting;

if (! function_exists('setting')) {
    /** Read a store setting (admin-editable), e.g. setting('store.phone'). */
    function setting(string $key, $default = null)
    {
        try {
            return Setting::get($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

if (! function_exists('media_url')) {
    /**
     * Public URL for a file stored on the public disk (e.g. "uploads/2024/05/x.jpg"). Absolute URLs pass through.
     * With $size (a commerce.images.sizes name such as 'thumbnail' / 'card', or an int N = fit inside N×N) the URL of
     * the best existing size variant, falling back to the original – see Pine\Commerce\Services\Media\Images.
     */
    function media_url(?string $path, string|int|null $size = null): string
    {
        if (! $path) {
            return asset('images/placeholder.png');
        }
        if (preg_match('#^(https?:)?//#', $path)) {
            return $path;
        }
        if ($size !== null && Pine\Commerce\Services\Media\Images::isLocal($relative = ltrim($path, '/'))) {
            $path = Pine\Commerce\Services\Media\Images::resolve($relative, $size)['path'];
        }

        return asset('storage/'.ltrim($path, '/'));
    }
}

if (! function_exists('image_srcset')) {
    /**
     * srcset value ("url 150w, url 400w, …") of the existing size variants of an upload that have the shape of $size
     * (every proportional variant when null); $format 'webp' lists their WebP twins. '' when there is no choice.
     */
    function image_srcset(?string $path, string|int|null $size = null, ?string $format = null): string
    {
        return Pine\Commerce\Services\Media\Images::srcset($path === null ? null : ltrim($path, '/'), $size, $format);
    }
}

if (! function_exists('money')) {
    /** Format an amount the way WooCommerce did: "£1,234.00" (symbol, decimals and separators from config commerce.currency). */
    function money($amount, bool $symbol = true): string
    {
        $c = (array) config('commerce.currency', []);
        $decimals = (int) ($c['decimals'] ?? 2);
        $value = round((float) $amount, $decimals);

        // the minus sign goes before the currency symbol: -£5.00, never £-5.00
        return ($value < 0 ? '-' : '').($symbol ? (string) ($c['symbol'] ?? '£') : '')
            .number_format(abs($value), $decimals, (string) ($c['decimal_separator'] ?? '.'), (string) ($c['thousands_separator'] ?? ','));
    }
}

if (! function_exists('commerce_admin_asset')) {
    /**
     * URL of a published back-office asset: public/{commerce.admin.assets_url}/{path} (copied there from
     * the package's resources/assets/admin by `php artisan commerce:publish`), cache-busted with ?v=filemtime.
     * $absolute = false returns a root-relative URL ("/admin-assets/css/x.css?v=…").
     */
    function commerce_admin_asset(string $path, bool $version = true, bool $absolute = true): string
    {
        $base = trim((string) config('commerce.admin.assets_url', 'vendor/commerce/admin'), '/');
        $file = $base.'/'.ltrim($path, '/');
        $url = $absolute ? asset($file) : '/'.$file;

        return $version ? $url.'?v='.(@filemtime(public_path($file)) ?: '1') : $url;
    }
}

if (! function_exists('commerce_admin_brand')) {
    /**
     * Back-office branding image URL: 'logo' | 'logo_light' | 'mark' | 'favicon'. commerce.admin.brand.{key} is a path
     * relative to public/; when it is not set, the image shipped with the admin assets is used.
     */
    function commerce_admin_brand(string $key): string
    {
        $path = config('commerce.admin.brand.'.$key);
        if (is_string($path) && $path !== '') {
            return asset(ltrim($path, '/'));
        }

        return commerce_admin_asset('img/'.(['logo_light' => 'logo-light.png', 'mark' => 'mark.png', 'favicon' => 'favicon-32.png'][$key] ?? 'logo.png'), false);
    }
}

// ---------------------------------------------------------------------------------------------------- themes (§7.4)

if (! function_exists('theme_view')) {
    /**
     * Render a theme-contract view (docs/ARCHITECTURE.md §8): the first of $names that exists in the active
     * theme chain ("theme::" namespace), e.g. theme_view(['catalog.category', 'catalog.archive'], $data).
     *
     * @param  string|list<string>  $names
     */
    function theme_view(string|array $names, array $data = []): Illuminate\Contracts\View\View
    {
        return Illuminate\Support\Facades\View::first(array_map(fn ($name) => 'theme::'.$name, (array) $names), $data);
    }
}

if (! function_exists('theme')) {
    /** The active storefront theme (Pine\Commerce\Theme\Theme). */
    function theme(): Pine\Commerce\Theme\Theme
    {
        return app(Pine\Commerce\Theme\ThemeManager::class)->active();
    }
}

if (! function_exists('theme_asset')) {
    /**
     * URL of a published theme asset, e.g. theme_asset('css/site.css') → /assets/css/site.css?v=… for a theme whose
     * public_path is "assets". Walks the theme chain: the first theme whose published copy of the file exists wins.
     */
    function theme_asset(string $path): string
    {
        return app(Pine\Commerce\Theme\ThemeManager::class)->asset($path);
    }
}

if (! function_exists('theme_config')) {
    /** Theme config value: 'menus.fallbacks.mega' = key fallbacks.mega of themes/{slug}/config/menus.php (child over parent). */
    function theme_config(string $key, $default = null)
    {
        return app(Pine\Commerce\Theme\ThemeManager::class)->config($key, $default);
    }
}

if (! function_exists('theme_setting')) {
    /** Admin-editable theme setting: setting('theme.{slug}.{key}') ?? theme.json default ?? $default. */
    function theme_setting(string $key, $default = null)
    {
        return app(Pine\Commerce\Theme\ThemeManager::class)->setting($key, $default);
    }
}

if (! function_exists('commerce_feature')) {
    /**
     * Is an optional store feature on? Reads config commerce.features.{key} (package default when the key is missing);
     * view-bearing features also need the active theme to declare them in theme.json "supports" (e.g. wishlist,
     * quick-view, blog). $theme = false checks the switch only (back office). See Pine\Commerce\Support\Features.
     */
    function commerce_feature(string $key, bool $theme = true): bool
    {
        return Pine\Commerce\Support\Features::enabled($key, $theme);
    }
}

if (! function_exists('menu_tree')) {
    /**
     * Normalised menu tree of the first location that has items (else $fallback):
     * list<array{label, url, badge, icon, class, new_tab, children}>. Use Pine\Commerce\View\Components\MenuComponent::href($url) for links.
     */
    function menu_tree(string|array $locations, array $fallback = []): array
    {
        return Pine\Commerce\View\Components\MenuComponent::treeFor($locations, $fallback);
    }
}

if (! function_exists('commerce_presenter')) {
    /** Class-string of the configured product presenter (commerce.catalog.presenter, when that class exists). */
    function commerce_presenter(): string
    {
        $class = config('commerce.catalog.presenter');

        return is_string($class) && class_exists($class) ? $class : Pine\Commerce\Services\Catalog\ProductPresenter::class;
    }
}
