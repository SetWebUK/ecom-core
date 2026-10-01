<?php

/*
|--------------------------------------------------------------------------
| Pine Commerce – WordPress/WooCommerce importer defaults
|--------------------------------------------------------------------------
| `php artisan commerce:import-wordpress` (docs/IMPORTER.md). Client overrides go in the app's
| config/commerce-import.php – mergeConfigFrom is shallow, so a top-level key set there REPLACES the whole key here.
| Every default is neutral: a new client gets a working import with no client config at all (`--core-only` runs
| with exactly this file).
*/

return [
    'source' => [
        'connection' => 'wordpress',                          // base connection (WP_DB_* env); --wp-path / --db-* override
        'wp_path' => env('WP_PATH'),                          // WordPress root (wp-config.php parsed, never included)
        'site_url' => env('WP_SITE_URL'),                     // running copy of the old site for rendered content (optional)
        'site_host' => env('WP_SITE_HOST'),                   // Host header for site_url (optional)
        'snapshots' => [storage_path('app/wp-reference/html')], // pre-rendered HTML: {path with / → __}.html, home.html
    ],

    // Hosts whose absolute links become relative (the source siteurl/home hosts are always included).
    'legacy_hosts' => [],

    'content' => [
        'replace' => [],                                      // ordered, case-insensitive search => replace for content + SEO strings
        'components' => ['wp_sitemap_page' => 'sitemap'],     // shortcode => <div data-component="…"> placeholder
        'system_pages' => ['basket', 'cart', 'checkout', 'my-account', 'shop'], // + the WooCommerce shop/cart/checkout/account pages
        'page_templates' => [],                               // page path or id => template ('contact-us' => 'contact')
        'selectors' => null,                                  // rendered theme content areas (null = RenderedTheme::DEFAULT_SELECTORS)
        'strip_shortcodes' => true,                           // remove unknown [shortcodes] left in post_content
        'keep_shortcodes' => [],                              // … except these
    ],

    'seo' => [
        'rendered_fallback' => true,                          // use the rendered <meta description>/<title> when the SEO plugin generated it
    ],

    'attributes' => [
        'filterable' => [],                                   // attribute slugs shown as shop filters, in order
        'map' => [],                                          // attribute slug => products column, e.g. ['brand' => 'brand']
    ],

    'menus' => [
        'by_term_id' => [],                                   // nav_menu term id => local location (imported first, in this order)
        'by_location' => [],                                  // WP theme location => local location (empty + no by_term_id: names kept)
        'import_unassigned' => true,                          // other nav menus → location "wp-{slug}"
    ],

    'orders' => [
        'source' => 'auto',                                   // auto|hpos|posts (auto = woocommerce_custom_orders_table_enabled)
        'meta_keys' => [],                                    // extra order meta copied into orders.meta
    ],

    'media' => [
        'disk' => 'public',                                   // --copy-uploads target disk (files land in uploads/)
        'upload_urls' => [],                                  // extra absolute upload base URLs (CDN) rewritten to /storage/uploads/
        'skip_dirs' => [],                                    // uploads sub-directories never copied (added to MediaFilesStep::SKIP_DIRS)
        'private_dirs' => [],                                 // copied to private_path instead (added to PRIVATE_DIRS)
        'private_path' => null,                               // null = storage/app/private/wp-uploads
        'quarantine_path' => null,                            // executables found in uploads; null = storage/app/private/quarantine-uploads
    ],

    'elementor' => [
        'css_path' => null,                                   // where --copy-uploads puts uploads/elementor/css/post-*.css (null = storage/app/import/elementor-css)
    ],

    'acf' => [
        'term_fields' => [],                                  // ACF field => categories column | ['column' => …, 'format' => html|text|raw|image|bool|decimal]
        'product_fields' => [],                               // ACF field => products column | [...]
    ],

    'settings' => [
        'defaults' => [],                                     // applied first; WooCommerce options and adapters override them
        'woocommerce' => null,                                // setting keys taken from WooCommerce options (null = all, see Adapters\WooCommerce::KEYS)
        'seed' => [],                                         // applied last – wins over everything
    ],

    'shipping' => [
        'zones' => true,                                      // WooCommerce zones/methods/classes as shipping zones (false = one flat, country-limited list, pre-v1.1)
    ],

    'adapters' => [
        'extra' => [],                                        // client adapter classes (App\Import\…)
        'disable' => [],                                      // adapter keys, or 'key:capability' (e.g. 'rank-math:redirects')
        'enable' => [],                                       // adapter keys forced on regardless of detection
    ],
];
