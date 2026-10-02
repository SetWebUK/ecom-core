<?php

namespace Pine\Commerce\Support;

use Throwable;

/**
 * Feature switches (config commerce.features.*, ARCHITECTURE.md §9.2). One place that knows what every switch is,
 * what it gates and which theme "supports" key a view-bearing feature needs.
 *
 *   Features::enabled('blog')          config switch AND (storefront) the active theme supports it – commerce_feature()
 *   Features::enabled('blog', false)   the config switch only (back office: admin pages exist whatever the theme)
 *
 * Gates wired in the package: routes (route middleware `commerce.feature:{key}` → 404 when off), admin sidebar items
 * and admin routes, storefront entry points in the default + client themes, sitemap entries, shortcodes, emails,
 * and importer steps (Features::IMPORT_STEPS). Missing keys in a client's config fall back to the package default
 * (CommerceServiceProvider merges the features block key by key).
 */
class Features
{
    /** key => [package default, what it gates] – shown read-only in Admin › Settings › System. */
    public const DEFINITIONS = [
        'blog' => [true, 'Blog index, posts, categories and RSS feed; admin Blog posts & Blog categories; posts in the sitemap; [blog_index] shortcode; importer step content.posts'],
        'wishlist' => [true, 'Wishlist toggle route, My account › Wishlist, heart buttons; importer step extras.wishlists'],
        'reviews' => [true, 'Product review form + route, star ratings, review JSON-LD; admin Reviews; importer step extras.reviews'],
        'stock_alerts' => [true, '“Email me when back in stock” form + route, back-in-stock emails on restock; admin Stock alerts; importer step extras.stock-alerts'],
        'newsletter' => [true, 'Newsletter sign-up form + route; admin Inbox › Newsletter'],
        'contact_form' => [true, 'Contact form route + [contact_form] shortcode; admin Inbox › Form submissions + dashboard messages; importer step extras.forms'],
        'order_tracking' => [true, 'Order-tracking form route + [order_tracking] shortcode'],
        'quick_view' => [true, 'Product quick-view fragment route and buttons'],
        'google_feed' => [true, 'feeds/google-shopping.xml and the feed fields under Settings › SEO'],
        'abandoned_carts' => [true, 'Admin Orders › Abandoned checkouts + Settings › Abandoned carts; reminder emails (only once switched on there) and their basket restore links'],
        'coupons' => [true, 'Discount-code field in basket/checkout + coupon routes; admin Discounts; importer step extras.coupons'],
        'guest_checkout' => [true, 'Checkout without an account (off: customers sign in or register first)'],
        'registration' => [true, 'Customer registration route + forms (also needs Settings › Checkout › “Customers can create an account”)'],
        'reports' => [true, 'Admin Analytics (reports + CSV export)'],
        'redirects' => [true, 'Redirect rules on old URLs; admin Content › Redirects; importer section redirects'],
        'multi_shipping' => [true, 'Customers choose between several delivery options (off: the first available option is applied)'],
        'product_condition' => [false, 'Product condition: admin field/filter/CSV, shop facet, itemCondition in JSON-LD and the feed'],
        'product_brand' => [true, 'Brand field/filter in admin, brand facet with logos, presenter brandName()/brandLogo()'],
        'spec_highlights' => [false, 'Spec highlights / card spec lines from commerce.catalog.spec_attributes'],
        'pay_in_3' => [false, 'Instalment line (“N payments of …”) on product pages (commerce.pay_in_3)'],
        'legacy_content' => [true, 'Imported WordPress markup: Elementor per-page CSS, WPBakery toggles, Font Awesome icons, WP shortcodes'],
        'wp_404_guess' => [true, 'Unknown old WordPress URLs: guess the new page/product by slug before the 404'],
        'add_to_cart_query' => [true, 'Old ?add-to-cart={id} links keep adding to the basket'],
        'product_csv' => [true, 'Admin Products › Import / Export (full product CSV export and import with column mapping, dry run and batches); commerce:products:export / commerce:products:import'],
        'updater' => [true, 'Admin Updates (administrators only): daily update check, dashboard notice + sidebar badge, password-approved pine/commerce updates with backup and rollback, skeleton file updates; commerce:update:* and commerce:skeleton:* commands'],
    ];

    /** Feature => theme.json "supports" key the storefront side needs (a theme without it shows no entry points). */
    public const THEME_SUPPORTS = [
        'wishlist' => 'wishlist', 'quick_view' => 'quick-view', 'blog' => 'blog', 'reviews' => 'reviews',
        'stock_alerts' => 'stock-alerts', 'newsletter' => 'newsletter', 'order_tracking' => 'order-tracking',
        'contact_form' => 'contact-form',
    ];

    /**
     * Importer steps that only run while a feature is on: step key, or a section/key prefix ending in '.'
     * (a step may also declare its own with a public feature(): ?string method).
     */
    public const IMPORT_STEPS = [
        'content.posts' => 'blog',
        'extras.reviews' => 'reviews',
        'extras.coupons' => 'coupons',
        'extras.wishlists' => 'wishlist',
        'extras.stock-alerts' => 'stock_alerts',
        'extras.forms' => 'contact_form',
        'redirects' => 'redirects',
    ];

    /** Is the feature on? $theme = also require the active theme to support a view-bearing feature (storefront). */
    public static function enabled(string $key, bool $theme = true): bool
    {
        $value = config('commerce.features.'.$key);
        if ($value === null) {
            $value = self::DEFINITIONS[$key][0] ?? false;
        }
        if (! filter_var($value, FILTER_VALIDATE_BOOL)) {
            return false;
        }
        if ($theme && isset(self::THEME_SUPPORTS[$key])) {
            try {
                return theme()->supports(self::THEME_SUPPORTS[$key]);
            } catch (Throwable) {
                return true;
            }
        }

        return true;
    }

    /** Package defaults of every switch. @return array<string,bool> */
    public static function defaults(): array
    {
        return array_map(fn (array $d) => $d[0], self::DEFINITIONS);
    }

    /** What a switch gates (for the System page / docs); null for a client-defined key. */
    public static function describe(string $key): ?string
    {
        return self::DEFINITIONS[$key][1] ?? null;
    }

    /**
     * Every switch with its configured value and the effective storefront value.
     *
     * @return list<array{key:string, on:bool, effective:bool, description:?string, default:?bool}>
     */
    public static function report(): array
    {
        $rows = [];
        foreach (array_replace(self::defaults(), (array) config('commerce.features', [])) as $key => $on) {
            $key = (string) $key;
            $on = filter_var($on, FILTER_VALIDATE_BOOL);
            $rows[] = ['key' => $key, 'on' => $on, 'effective' => $on && self::enabled($key), 'description' => self::describe($key),
                'default' => self::DEFINITIONS[$key][0] ?? null];
        }

        return $rows;
    }

    /** The feature an importer step depends on, if any. */
    public static function forImportStep(object $step): ?string
    {
        if (method_exists($step, 'feature') && ($feature = $step->feature())) {
            return (string) $feature;
        }
        $key = method_exists($step, 'key') ? (string) $step->key() : '';
        $section = method_exists($step, 'section') ? (string) $step->section() : '';
        foreach (self::IMPORT_STEPS as $prefix => $feature) {
            if ($key === $prefix || $section === $prefix || str_starts_with($key, $prefix.'.')) {
                return $feature;
            }
        }

        return null;
    }
}
