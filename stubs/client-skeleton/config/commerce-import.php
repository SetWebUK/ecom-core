<?php

/*
|--------------------------------------------------------------------------
| WordPress / WooCommerce importer - {{CLIENT_NAME}}
|--------------------------------------------------------------------------
| Package defaults and the meaning of every key: vendor/pine/commerce/config/commerce-import.php and
| pine/commerce docs/IMPORTER.md. Shallow merge per top-level key (a key set here replaces the package block).
| The source database is the read-only "wordpress" connection (WP_DB_* in .env, config/database.php).
*/

return [
    'source' => [
        'connection' => 'wordpress',
        'wp_path' => env('WP_PATH'),            // WordPress root: uploads are copied from {wp_path}/wp-content/uploads
        'site_url' => env('WP_SITE_URL'),       // running copy of the old site, for rendered-content crawling (optional)
        'site_host' => env('WP_SITE_HOST'),
        'snapshots' => storage_path('app/wp-reference/html'),
    ],

    // Old host names (live, www/non-www, old staging hosts) whose links and redirect targets are imported as relative
    // URLs; the WordPress siteurl/home hosts are added automatically. Same list in config/commerce.php legacy_hosts.
    'legacy_hosts' => [
        // 'www.{{CLIENT_SLUG}}.co.uk', '{{CLIENT_SLUG}}.co.uk', 'staging.{{CLIENT_SLUG}}.co.uk',
    ],

    'content' => [
        'replace' => [],                        // e.g. ['staging.client.co.uk' => 'client.co.uk']
        'system_pages' => ['basket', 'cart', 'checkout', 'my-account', 'shop'],
        'page_templates' => [],                 // WordPress page path => local template, e.g. ['contact-us' => 'contact', 'faq' => 'faq']
    ],

    'attributes' => [
        'filterable' => [],                     // attribute slugs shown as shop filters, e.g. ['brand', 'colour', 'size']
        'map' => [],                            // attribute slug => products column, e.g. ['brand' => 'brand']
    ],

    'menus' => [
        'by_location' => [],                    // WP theme location => local location, e.g. ['primary' => 'main', 'footer' => 'footer_company']
        'by_term_id' => [],                     // or by nav_menu term id
    ],

    // Client adapters (app/Import/*) - see app/Import/README.md
    'adapters' => ['extra' => [], 'disable' => []],
];
