<?php

/*
|--------------------------------------------------------------------------
| Pine Commerce - {{CLIENT_NAME}} overrides
|--------------------------------------------------------------------------
| Package defaults (every key, documented): vendor/pine/commerce/config/commerce.php.
| IMPORTANT: the merge is SHALLOW per top-level key - a key you set here replaces the package's whole key, so copy
| the complete block (e.g. all of 'features') and change what you need. Keys you leave out keep the package default.
| Values that are also cookie/header names (cart.cookie, checkout.attribution_cookie, catalog.ajax_header) must never
| change after go-live: customers would lose their baskets.
*/

return [
    // Storefront theme (themes/{slug}); switch per environment with COMMERCE_THEME in .env
    'theme' => env('COMMERCE_THEME', '{{CLIENT_SLUG}}'),

    // Hosts of the old WordPress site: absolute links to them in menus are made relative when rendered. The importer
    // uses its own list (config/commerce-import.php legacy_hosts) - keep both the same
    'legacy_hosts' => [
        // 'www.{{CLIENT_SLUG}}.co.uk', '{{CLIENT_SLUG}}.co.uk',
    ],

    // Feature switches - see the package config for what each one gates. A feature the theme does not support stays off.
    'features' => [
        'blog' => true,
        'wishlist' => true,
        'reviews' => true,
        'stock_alerts' => true,
        'newsletter' => true,
        'contact_form' => true,
        'order_tracking' => true,
        'quick_view' => true,
        'google_feed' => true,
        'abandoned_carts' => true,
        'product_condition' => false,
        'product_brand' => true,
        'spec_highlights' => false,
        'pay_in_3' => false,
        'legacy_content' => true,   // imported WordPress content (Elementor/WPBakery markup, shortcodes)
        'wp_404_guess' => true,     // unknown old URLs: guess the new page by slug before the 404
        'add_to_cart_query' => true, // old ?add-to-cart=ID links keep working
        'product_csv' => true,      // admin Products › Import / Export (full product CSV, WooCommerce exports accepted)
    ],

    // 'store' => ['country' => 'GB', 'countries' => ['GB' => 'United Kingdom (UK)'], 'timezone' => 'Europe/London', 'locale' => 'en_GB'],
    // 'currency' => ['code' => 'GBP', 'symbol' => '£', 'decimals' => 2, 'decimal_separator' => '.', 'thousands_separator' => ','],
    // 'orders' => ['reference_prefix' => 'ORD'],
    // Image sizes generated on upload (package default: thumbnail 150 crop, card 400, medium 800, large 1600 + WebP).
    // Copy the whole 'images' block from the package config to change them, then run
    // `php artisan commerce:images:generate --missing` for the media that is already there.
    // 'images' => [...],
    // 'admin' => ['path' => 'admin', 'assets_url' => 'vendor/commerce/admin',
    //     'brand' => ['name' => '{{CLIENT_NAME}}', 'logo' => 'brand/admin/logo.png', 'logo_light' => null, 'mark' => null, 'favicon' => null],
    //     'menu' => []],
];
