<?php

namespace Pine\Commerce\Theme;

use Pine\Commerce\Console\ThemePublishCommand;

/**
 * The views core renders (docs/ARCHITECTURE.md §8) and the checks behind `commerce:theme:check`.
 * Each row lists alternative names – a theme must resolve at least one of them through its chain.
 */
class ThemeContract
{
    /** Required of every theme (the default theme provides all, so child themes pass through their chain). */
    public const REQUIRED = [
        'layout' => ['layouts.app'],
        'home' => ['home', 'pages.home'],
        'page' => ['pages.default'],
        'page.contact' => ['pages.contact', 'pages.default'],
        'page.faq' => ['pages.faq', 'pages.default'],
        'catalog' => ['catalog.archive'],
        'catalog.results' => ['catalog.partials.results'],
        'catalog.filters' => ['partials.filters'],
        'product.show' => ['product.show'],
        'checkout.show' => ['checkout.show'],
        'checkout.summary' => ['checkout.partials.summary'],
        'checkout.shipping-methods' => ['checkout.partials.shipping-methods'],
        'checkout.card-icons' => ['checkout.partials.card-icons'],
        'checkout.thankyou' => ['checkout.thankyou'],
        'checkout.pay' => ['checkout.pay'],
        'checkout.verify-email' => ['checkout.verify-email'],
        'auth.login' => ['auth.login'],
        'auth.lost-password' => ['auth.lost-password'],
        'auth.reset-password' => ['auth.reset-password'],
        'account.logout-confirm' => ['account.logout-confirm'],
        'account.dashboard' => ['account.dashboard'],
        'account.orders' => ['account.orders'],
        'account.view-order' => ['account.view-order'],
        'account.invalid-order' => ['account.invalid-order'],
        'account.addresses' => ['account.addresses'],
        'account.edit-address' => ['account.edit-address'],
        'account.edit-account' => ['account.edit-account'],
        'account.downloads' => ['account.downloads'],
        'partials.footer-menu' => ['partials.footer-menu'],
        'partials.page-content' => ['partials.page-content'],
        'partials.html-sitemap' => ['partials.html-sitemap'],
        'partials.pagination' => ['partials.pagination'],
        'errors.404' => ['errors.404'],
        'errors.410' => ['errors.410', 'errors.404'],
        'errors.500' => ['errors.500'],
        'emails.layout' => ['emails.layouts.base'],
        'emails.order-details' => ['emails.partials.order-details'],
        'emails.addresses' => ['emails.partials.addresses'],
        'emails.admin-new-order' => ['emails.orders.admin-new-order'],
        'emails.admin-cancelled-order' => ['emails.orders.admin-cancelled-order'],
        'emails.admin-failed-order' => ['emails.orders.admin-failed-order'],
        'emails.customer-processing-order' => ['emails.orders.customer-processing-order'],
        'emails.customer-on-hold-order' => ['emails.orders.customer-on-hold-order'],
        'emails.customer-completed-order' => ['emails.orders.customer-completed-order'],
        'emails.customer-refunded-order' => ['emails.orders.customer-refunded-order'],
        'emails.customer-note' => ['emails.orders.customer-note'],
        'emails.new-account' => ['emails.account.new-account'],
        'emails.reset-password' => ['emails.account.reset-password'],
        'emails.contact-submitted' => ['emails.contact-submitted'],
    ];

    /** Rows required only when the theme declares the feature in "supports". */
    public const FEATURES = [
        'side-cart' => ['cart.side-cart'],
        'quick-view' => ['product.quick-view'],
        'mega-menu' => ['partials.mega-menu'],
        'mobile-menu' => ['partials.mobile-menu'],
        'wishlist' => ['account.wishlist'],
        'blog' => ['blog.index', 'blog.category', 'blog.show', 'blog.partials.grid'],
        'order-tracking' => ['partials.order-tracking'],
        'contact-form' => ['partials.contact-form'],
    ];

    /** Every view file of the contract, as a flat list (for docs and the child-theme README). */
    public static function views(): array
    {
        $names = [];
        foreach (static::REQUIRED as $alternatives) {
            array_push($names, ...$alternatives);
        }
        foreach (static::FEATURES as $views) {
            array_push($names, ...$views);
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** @return list<string> problems (empty = valid) */
    public static function check(ThemeManager $themes, string $slug): array
    {
        if (! $themes->exists($slug)) {
            return ["Theme [{$slug}] is not installed."];
        }
        $problems = [];
        try {
            $chain = $themes->chain($slug);
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
        $theme = $chain[0];
        $manifest = $theme->manifest();
        foreach (['name', 'slug', 'version'] as $key) {
            if (empty($manifest[$key])) {
                $problems[] = "theme.json: \"{$key}\" is required.";
            }
        }
        if (! preg_match('/^[a-z0-9-]+$/', $slug)) {
            $problems[] = 'theme.json: "slug" may only contain a-z, 0-9 and dashes.';
        }
        if (! $theme->isCore() && basename($theme->path) !== $slug) {
            $problems[] = "theme.json: slug \"{$slug}\" must match the directory name \"".basename($theme->path).'".';
        }
        if (! array_key_exists('supports', $manifest) || ! is_array($manifest['supports'])) {
            $problems[] = 'theme.json: "supports" (list of feature keys) is required.';
        }
        if (($parent = $theme->parent()) !== null && ! $themes->exists($parent)) {
            $problems[] = "theme.json: parent theme [{$parent}] is not installed.";
        }
        $public = $theme->publicPath();
        if (in_array(explode('/', $public)[0], ThemePublishCommand::FORBIDDEN, true) || str_contains($public, '..')) {
            $problems[] = "theme.json: public_path \"{$public}\" is not allowed.";
        }
        try {
            $theme->definition();
        } catch (\Throwable $e) {
            $problems[] = $e->getMessage();
        }

        $dirs = array_values(array_filter(array_map(fn (Theme $t) => $t->viewsPath(), $chain), 'is_dir'));
        $resolves = function (string $view) use ($dirs): bool {
            $rel = str_replace('.', '/', $view);
            foreach ($dirs as $dir) {
                if (is_file("{$dir}/{$rel}.blade.php") || is_file("{$dir}/{$rel}.php")) {
                    return true;
                }
            }

            return false;
        };
        foreach (static::REQUIRED as $key => $alternatives) {
            if (! array_filter($alternatives, $resolves)) {
                $problems[] = "view missing for contract row \"{$key}\": ".implode(' or ', $alternatives);
            }
        }
        foreach (static::FEATURES as $feature => $views) {
            if (! $theme->supports($feature)) {
                continue;
            }
            foreach ($views as $view) {
                if (! $resolves($view)) {
                    $problems[] = "supports \"{$feature}\" but view {$view} is missing.";
                }
            }
        }

        return $problems;
    }
}
