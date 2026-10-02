<?php

/*
|--------------------------------------------------------------------------
| Pine Commerce – package defaults
|--------------------------------------------------------------------------
| Neutral defaults for a new client. The client app's config/commerce.php overrides any key (merged per top-level
| key by mergeConfigFrom, so a client file that sets e.g. 'catalog' must repeat every 'catalog' sub-key it needs –
| a client file normally repeats them all). Contract: docs/ARCHITECTURE.md §10.1.
*/

return [
    'theme' => env('COMMERCE_THEME', 'default'),

    // Transitional autoloader for old App\… class names (ARCHITECTURE.md §5.4). Reserved: the core extraction rewrote
    // every reference and no serialized class names exist, so no aliases are registered (see §18).
    'legacy_aliases' => false,

    // Hosts whose absolute links in content/menus are treated as local (made relative).
    'legacy_hosts' => [],

    'routes' => ['storefront' => true, 'admin' => true],

    'urls' => ['trailing_slash' => true],

    'admin' => [
        'path' => 'admin',
        // public/ sub-directory the admin assets are published (copied) to by `php artisan commerce:publish`
        'assets_url' => 'vendor/commerce/admin',
        // paths relative to public/; null = the image shipped with the admin assets
        'brand' => ['name' => null, 'logo' => null, 'logo_light' => null, 'mark' => null, 'favicon' => null],
        'menu' => [],
    ],

    'store' => [
        'country' => 'GB',
        'countries' => ['GB' => 'United Kingdom (UK)'],
        'timezone' => 'Europe/London',
        'locale' => 'en_GB',
    ],

    'currency' => ['code' => 'GBP', 'symbol' => '£', 'decimals' => 2, 'decimal_separator' => '.', 'thousands_separator' => ','],

    /*
    | Feature switches. Each one turns a storefront/admin capability on or off without code changes; the client's
    | config/commerce.php repeats the whole block (shallow merge). A view-bearing feature is only effective when the
    | active theme lists it in theme.json "supports" (helper commerce_feature()); commerce:doctor and
    | Admin › Settings › System report the ones that are on but unsupported. The comments say what each switch gates
    | (contract: docs/ARCHITECTURE.md §9.2). Route NAMES never change - a switched-off feature's routes are
    | not registered (404) and its admin menu entries are hidden. Check CHANGELOG.md / docs/UPGRADING.md
    | for which gates are wired in your package version before relying on "off" for a route.
    */
    'features' => [
        'blog' => true,               // blog index/posts/categories + RSS feed routes, admin Posts & Blog categories, posts in the sitemap
        'wishlist' => true,           // wishlist toggle route, My account › Wishlist, heart buttons (theme: supports "wishlist")
        'reviews' => true,            // product review form route, admin Reviews, star ratings in JSON-LD
        'stock_alerts' => true,       // "email me when back in stock" route, admin Stock alerts, back-in-stock emails on restock
        'newsletter' => true,         // newsletter sign-up route, admin Inbox › Newsletter
        'contact_form' => true,       // contact form route + [contact_form] shortcode, admin Inbox › Form submissions
        'order_tracking' => true,     // order-tracking route + [woocommerce_order_tracking] shortcode
        'quick_view' => true,         // product quick-view fragment route (theme: supports "quick-view")
        'google_feed' => true,        // feeds/google-shopping.xml + the feed fields under Settings › SEO
        'abandoned_carts' => true,    // admin Orders › Abandoned checkouts + Settings › Abandoned carts (reminder emails: off until switched on there)
        'coupons' => true,            // discount-code field in basket/checkout + coupon routes, admin Discounts, importer step extras.coupons
        'guest_checkout' => true,     // checkout without an account (off: guests are sent to My account to sign in or register first)
        'registration' => true,       // customer registration route/forms + "create an account" at checkout (also Settings › Checkout)
        'reports' => true,            // admin Analytics (reports + CSV export)
        'redirects' => true,          // redirect rules answered on old URLs, admin Content › Redirects, importer section redirects
        'multi_shipping' => true,     // several delivery options at checkout (off: only the first available option is offered)
        'product_condition' => false, // product condition (new/used/…): admin field + filter + CSV, shop facet, itemCondition in JSON-LD/feed
        'product_brand' => true,      // brand field/filter in admin, brand facet (with logos), presenter brandName()/brandLogo()
        'spec_highlights' => false,   // spec highlights / card spec lines from commerce.catalog.spec_attributes
        'pay_in_3' => false,          // instalment line ("N payments of …") on product pages (commerce.pay_in_3 min/max/instalments)
        'legacy_content' => true,     // imported WordPress markup: Elementor per-page CSS, WPBakery toggles, Font Awesome icons, WP shortcodes
        'wp_404_guess' => true,       // unknown old WordPress URLs: guess the new page/product by slug before the 404
        'add_to_cart_query' => true,  // old ?add-to-cart={id} links keep adding to the basket (HandleAddToCartQuery middleware)
        'product_csv' => true,        // admin Products › Import / Export: full product CSV export + import (mapping, dry run, batches), commerce:products:* commands
        'updater' => true,            // admin Updates (administrators): update check, approved pine/commerce updates, skeleton file updates; commerce:update:* / commerce:skeleton:*
    ],

    'catalog' => [
        'presenter' => 'Pine\\Commerce\\Services\\Catalog\\ProductPresenter',
        'per_page' => 24,
        'filters' => [],
        'facet_sorter' => 'Pine\\Commerce\\Services\\Catalog\\DefaultFacetSorter',
        'condition_attribute' => 'condition',
        'condition_label_strip' => [],
        'condition_schema_map' => ['used' => 'UsedCondition', '*' => 'NewCondition'], // term needle => schema.org itemCondition
        'brand_attribute' => 'brand',
        'known_brands' => [],
        'spec_attributes' => [],
        'ajax_header' => 'X-Commerce-Catalog',
        'legacy_page_params' => [],
    ],

    /*
    | Full product CSV import/export (feature switch product_csv; docs/PRODUCT-CSV.md). An import runs in batches driven
    | by the admin page (no queue worker): chunk_size rows per request (hard cap 200) within time_budget seconds.
    */
    'product_csv' => [
        'chunk_size' => 25,        // rows per batch request
        'time_budget' => 20,       // seconds per batch (a batch stops after the row that passes it)
        'max_rows' => 10000,       // rows per file
        'max_file_kb' => 20480,    // upload size limit
        'max_image_kb' => 10240,   // largest image downloaded from a URL
        'image_timeout' => 15,     // seconds per image download
        'url_guard' => true,       // image downloads only from public internet hosts (no private/reserved addresses)
        'keep_days' => 14,         // uploaded files + reports are deleted after this many days
        'disk' => 'local',         // private disk holding uploads + reports (storage/app/private/commerce/product-imports)
    ],

    'cart' => ['cookie' => 'commerce_cart', 'open_cookie' => 'commerce_open_cart'],

    'checkout' => ['attribution_cookie' => 'commerce_attr'],

    'forms' => ['contact' => ['prefix' => 'cf_']],

    'orders' => ['reference_prefix' => 'ORD'],

    'pay_in_3' => ['min' => 30, 'max' => 2000, 'instalments' => 3],

    // Tax defaults (Admin › Settings › Tax overrides each key). New stores: UK-style prices including VAT, shown
    // including VAT, rounded per line. A store upgraded from before v1.1 keeps its behaviour: the migration saves
    // "prices exclude tax" when it had a VAT rate, and with no rates no tax is charged in either mode.
    'tax' => [
        'enabled' => true,
        'prices_include_tax' => true,
        'display_shop' => 'incl',                 // incl|excl ('' = as entered)
        'display_cart' => 'incl',                 // incl|excl ('' = as entered)
        'rounding' => 'line',                     // line|order
        'based_on' => 'shipping',                 // shipping|billing|base
        'shipping_taxable' => true,
        'shipping_tax_class' => 'inherit',        // inherit|{tax class slug}
        'shipping_prices_include_tax' => '',      // ''|yes|no ('' = like product prices)
        'adjust_non_base_prices' => true,
        'price_suffix' => '',                     // e.g. 'inc. VAT'
        'label' => 'VAT',
        'install_rates' => 'uk',                  // rates commerce:install seeds: uk|eu|uk+eu|none
    ],

    'shipping' => [
        'install_zones' => true,                  // commerce:install creates a UK zone with free delivery
        'carriers' => [
            'Royal Mail' => 'https://www.royalmail.com/track-your-item#/tracking-results/{number}',
            'DPD' => 'https://track.dpd.co.uk/parcels/{number}',
            'DHL' => 'https://www.dhl.com/gb-en/home/tracking.html?tracking-id={number}',
            'UPS' => 'https://www.ups.com/track?tracknum={number}',
            'FedEx' => 'https://www.fedex.com/fedextrack/?trknbr={number}',
            'Evri' => 'https://www.evri.com/track/parcel/{number}',
        ],
    ],

    'feeds' => ['google' => ['default_category' => null, 'default_condition' => 'new', 'condition_map' => ['used' => 'used']]],

    'documents' => ['logo' => null],             // fallback logo of printed invoices / packing slips when Settings has no store logo

    /*
    | Invoices & PDF documents (v1.1, docs/INVOICES.md). Every key is the DEFAULT of the matching Admin › Settings ›
    | Invoices field ("invoices.{key}" setting), so the shop owner can change it without a deploy. New installs get
    | sequential numbers, invoices attached to the order emails and a customer download link.
    */
    'invoices' => [
        'numbering' => true,           // sequential invoice numbers (false: the invoice number is the order number, as before v1.1)
        'prefix' => 'INV-',            // e.g. INV-00001; {Y} / {y} / {m} are replaced with the invoice date
        'suffix' => '',
        'padding' => 5,                // zero-pad the number to this many digits (0 = no padding)
        'start' => 1,                  // first invoice number of a new shop (Settings › Invoices › Next number moves it forward)
        'assign_on' => 'paid',         // paid: when the order becomes processing or completed | completed: only when completed
        'attach' => [                  // attach the invoice PDF to these customer emails
            'customer_processing' => true,
            'customer_completed' => true,
            'customer_invoice' => true,    // the "Order details / invoice" email sent from the admin order page (v1.2; paid orders only)
        ],
        'customer_download' => true,   // "Download invoice" in My account › View order and on the order-received page (guests: order key link)
        'paper' => 'a4',               // a4 | letter
        'cache' => false,              // keep generated PDFs in storage/app/private/invoices (never public); Regenerate clears them
    ],
    'media' => ['placeholder' => 'images/placeholder.png'],

    /*
    | Image sizes (docs/THEMES.md "Images"). Uploads through the admin (media library, product photos, category / post /
    | theme images) are EXIF-oriented, scaled down to max_dimension and get a variant per size next to the original,
    | named like WordPress's ("photo-400x300.jpg"), plus WebP twins ("photo-400x300.webp", "photo.webp"). Imported
    | media keeps its WordPress sizes; `php artisan commerce:images:generate --missing` adds the missing ones.
    | Themes ask for a size by name: media_url($path, 'card'), image_srcset($path, 'card'), <x-media-image size="card">
    | – always the best file that exists, else the original. Changing sizes later: run the command again.
    */
    'images' => [
        'generate_on_upload' => true,   // false: uploads are stored as sent (no variants, no orientation/downscale)
        'driver' => 'auto',             // auto (Imagick when loaded, else GD) | imagick | gd – PHP extensions only
        'sizes' => [                    // name => width/height (0 = any) + crop; an int N = fit inside N×N
            'thumbnail' => ['width' => 150, 'height' => 150, 'crop' => true],   // admin + small thumbnails (WordPress size)
            'card' => ['width' => 400, 'height' => 400, 'crop' => false],       // product cards, category tiles
            'medium' => ['width' => 800, 'height' => 800, 'crop' => false],     // blog cards, quick view, gallery on phones
            'large' => ['width' => 1600, 'height' => 1600, 'crop' => false],    // product gallery, blog hero
        ],
        'webp' => true,                 // also write a WebP twin of each variant and of the original (when the driver can)
        'picture_webp' => true,         // <x-media-image> adds a WebP <source> when every candidate has a twin
        'quality' => ['jpg' => 82, 'webp' => 80, 'avif' => 60, 'png' => 8],  // png = zlib level 0–9
        'auto_orient' => true,          // rotate phone photos by their EXIF orientation (upload + variants)
        'max_dimension' => 2560,        // scale new uploads down to fit 2560×2560 (null/0 = keep as uploaded)
        'strip_metadata' => true,       // drop EXIF/GPS from generated files (colour profile kept)
    ],

    'settings' => ['defaults' => []],

    /*
    | Core scheduled tasks (Pine\Commerce\Scheduling\Scheduler, docs/PLAYBOOK.md "Cron"). One cron line runs them all:
    |   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
    | Nothing depends on cron: without a heartbeat, unpaid orders are cancelled on checkout visits and – web_fallback –
    | due tasks run after a storefront response (at most every 5 minutes). Owner switches (abandoned-cart reminders,
    | low-stock email, basket retention) are settings; these are the developer switches. `commerce:schedule:status`.
    */
    'scheduler' => [
        'enabled' => true,                   // register the tasks with Laravel's scheduler at all
        'tasks' => [
            'orders.cancel-unpaid' => true,      // every 5 min: unpaid card/PayPal orders past Settings › "Hold stock" minutes
            'carts.abandoned-emails' => true,    // every 10 min: reminders – only while Settings › Abandoned carts › Send is on
            'stock.back-in-stock' => true,       // hourly: waiting back-in-stock alerts for products that are available again
            'catalog.sale-prices' => true,       // every 5 min: products.price follows scheduled sale start/end dates
            'maintenance.prune' => true,         // daily 03:40: old guest baskets, expired sessions and password-reset links
            'inventory.low-stock-email' => true, // daily 07:00: only while Settings › Scheduled tasks › Low-stock email is on
            'updates.check' => true,             // daily 06:15: look for a newer pine/commerce release (never installs – Admin › Updates)
        ],
        'web_fallback' => true,              // no cron detected: run due tasks after storefront responses
        'heartbeat_minutes' => 5,            // cron counts as running when schedule:run beat within this many minutes
    ],

    /*
    | Admin › Updates (feature switch "updater", administrators only; docs/PLAYBOOK.md part 3.6). Updates are found
    | automatically (daily task updates.check, "Check now") but only ever installed after an administrator approves
    | them with their password (or `php artisan commerce:update:run --approve --yes`).
    */
    'updater' => [
        'repository' => env('COMMERCE_UPDATER_REPOSITORY'), // null = the pine/commerce repository in composer.json
        'default_repository' => 'https://github.com/SetWebUK/ecom-core.git', // when composer.json names none (e.g. installed from a registry)
        'skeleton_repository' => 'https://github.com/SetWebUK/ecom-skeleton.git', // skeleton file updates (.commerce-skeleton.json may name another)
        'github_token' => env('COMMERCE_UPDATER_GITHUB_TOKEN'), // optional: private repositories / GitHub API rate limit
        'check' => true,                     // the daily scheduled check (scheduler task updates.check)
        'php_binary' => env('COMMERCE_PHP_BINARY'),         // PHP CLI for `php artisan …` (null = detected; a web request's PHP_BINARY is not the CLI)
        'composer_binary' => env('COMMERCE_COMPOSER_BINARY'), // null = `composer` / `composer.phar` in PATH
        'mysqldump_binary' => null,          // null = `mysqldump` / `mariadb-dump` in PATH
        'git_binary' => null,                // null = `git` in PATH
        'home' => env('COMMERCE_UPDATER_HOME'),             // HOME for composer/git started from the web (null = $HOME or the account's home)
        'composer_home' => env('COMMERCE_COMPOSER_HOME'),   // null = $COMPOSER_HOME, ~/.config/composer or ~/.composer
        'path' => null,                      // working directory (lock, logs, skeleton checkouts); null = storage/app/private/updater
        'backup' => true,                    // back the database up before every update (mysqldump / SQLite copy)
        'backup_path' => null,               // null = storage/app/private/updater/backups (never under public/)
        'keep_backups' => 5,                 // newest N database backups kept
        'min_free_mb' => 1024,               // free disk space needed to start an update
        'timeout' => 900,                    // seconds per composer / mysqldump step
        'http_timeout' => 10,                // seconds per GitHub API / raw file request
        'git_timeout' => 120,                // seconds per git ls-remote / fetch
        'project_path' => null,              // the project root (null = base_path(); tests only)
    ],

    'payments' => [
        'gateways' => [
            'stripe' => 'Pine\\Commerce\\Services\\Payments\\Gateways\\StripeGateway',
            'paypal' => 'Pine\\Commerce\\Services\\Payments\\Gateways\\PaypalGateway',
            'bacs' => 'Pine\\Commerce\\Services\\Payments\\Gateways\\BacsGateway',
        ],
    ],

    'content' => [
        'shortcode_aliases' => [],   // alias => core shortcode, e.g. ['old_contact_form' => 'contact_form']
        'legacy_class_prefix' => 'wp-', // classes of generated legacy-content markup: {prefix}legacy-toggle, {prefix}fa …
    ],
];
