<?php

namespace Pine\Commerce\Import\Source;

/**
 * What the source site is (docs/ARCHITECTURE.md §12.2): versions, active plugins, theme, permalink settings,
 * WooCommerce settings and where orders are stored. Built once per run by detect(); plain public properties so tests
 * can build a fake profile with `new SiteProfile(...)`.
 */
class SiteProfile
{
    public function __construct(
        public string $siteUrl = '',
        public string $homeUrl = '',
        public string $blogName = '',
        public ?string $wpVersion = null,
        public ?string $wpDbVersion = null,
        public ?string $wooVersion = null,
        public array $activePlugins = [],
        public string $theme = '',
        public string $template = '',
        public string $permalinkStructure = '',
        public string $categoryBase = '',
        public string $tagBase = '',
        public string $timezone = 'UTC',
        public int $pageOnFront = 0,
        public int $pageForPosts = 0,
        /** @var array<string,int> cart|checkout|myaccount|shop|terms => page id */
        public array $wooPages = [],
        /** @var array<string,mixed> see detect() */
        public array $woo = [],
        public string $ordersStorage = 'posts',
        public bool $hposSync = false,
        public string $uploadsUrl = '',
        public string $prefix = '',
        public string $database = '',
        /** @var array<string,int> nav_menu_locations theme_mod: location => nav_menu term id */
        public array $menuLocations = [],
    ) {}

    public static function detect(WordPressSource $wp): self
    {
        $o = fn (string $name, $default = null) => $wp->option($name, $default);
        $siteUrl = rtrim((string) $o('siteurl', ''), '/');
        $homeUrl = rtrim((string) $o('home', $siteUrl), '/');
        $stylesheet = (string) $o('stylesheet', '');
        $mods = (array) $o('theme_mods_'.$stylesheet, []);

        $permalinks = (array) $o('woocommerce_permalinks', []);
        $hposEnabled = $o('woocommerce_custom_orders_table_enabled') === 'yes';
        $hposTables = $wp->hasTable('wc_orders');

        $uploadsUrl = trim((string) $o('upload_url_path', ''));
        if ($uploadsUrl === '') {
            $uploadPath = trim((string) $o('upload_path', ''), '/');
            $uploadsUrl = $siteUrl.'/'.($uploadPath !== '' && ! str_starts_with($uploadPath, '/') ? $uploadPath : 'wp-content/uploads');
        }

        $woo = [
            'currency' => (string) $o('woocommerce_currency', 'GBP'),
            'currency_pos' => (string) $o('woocommerce_currency_pos', 'left'),
            'decimals' => (int) $o('woocommerce_price_num_decimals', 2),
            'thousand_sep' => (string) $o('woocommerce_price_thousand_sep', ','),
            'decimal_sep' => (string) $o('woocommerce_price_decimal_sep', '.'),
            'calc_taxes' => $o('woocommerce_calc_taxes') === 'yes',
            'prices_include_tax' => $o('woocommerce_prices_include_tax') === 'yes',
            'tax_display_shop' => (string) $o('woocommerce_tax_display_shop', 'excl'),
            'tax_display_cart' => (string) $o('woocommerce_tax_display_cart', 'excl'),
            'tax_based_on' => (string) $o('woocommerce_tax_based_on', 'shipping'),
            'tax_round_at_subtotal' => $o('woocommerce_tax_round_at_subtotal') === 'yes',
            'shipping_tax_class' => (string) $o('woocommerce_shipping_tax_class', 'inherit'),
            'price_display_suffix' => (string) $o('woocommerce_price_display_suffix', ''),
            'tax_classes' => (string) $o('woocommerce_tax_classes', ''),
            'default_country' => (string) $o('woocommerce_default_country', ''),
            'allowed_countries' => (string) $o('woocommerce_allowed_countries', 'all'),
            'specific_allowed_countries' => array_values((array) $o('woocommerce_specific_allowed_countries', [])),
            'ship_to_countries' => (string) $o('woocommerce_ship_to_countries', ''),
            'weight_unit' => (string) $o('woocommerce_weight_unit', 'kg'),
            'dimension_unit' => (string) $o('woocommerce_dimension_unit', 'cm'),
            'manage_stock' => $o('woocommerce_manage_stock', 'yes') === 'yes',
            'hold_stock_minutes' => $o('woocommerce_hold_stock_minutes'),
            'notify_low_stock_amount' => $o('woocommerce_notify_low_stock_amount'),
            'email_from_name' => $o('woocommerce_email_from_name'),
            'email_from_address' => $o('woocommerce_email_from_address'),
            'permalinks' => [
                'product_base' => (string) ($permalinks['product_base'] ?? ''),
                'category_base' => (string) ($permalinks['category_base'] ?? ''),
                'tag_base' => (string) ($permalinks['tag_base'] ?? ''),
                'attribute_base' => (string) ($permalinks['attribute_base'] ?? ''),
            ],
        ];

        $locations = [];
        foreach ((array) ($mods['nav_menu_locations'] ?? []) as $location => $termId) {
            if ((int) $termId > 0) {
                $locations[(string) $location] = (int) $termId;
            }
        }

        return new self(
            siteUrl: $siteUrl,
            homeUrl: $homeUrl,
            blogName: html_entity_decode((string) $o('blogname', ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            wpVersion: self::wpVersion((string) $o('db_version', '')),
            wpDbVersion: ($v = $o('db_version')) !== null ? (string) $v : null,
            wooVersion: ($v = $o('woocommerce_version')) !== null ? (string) $v : null,
            activePlugins: $wp->activePlugins(),
            theme: $stylesheet,
            template: (string) $o('template', ''),
            permalinkStructure: (string) $o('permalink_structure', ''),
            categoryBase: (string) $o('category_base', ''),
            tagBase: (string) $o('tag_base', ''),
            timezone: self::timezone((string) $o('timezone_string', ''), $o('gmt_offset')),
            pageOnFront: $o('show_on_front') === 'page' ? (int) $o('page_on_front', 0) : 0,
            pageForPosts: $o('show_on_front') === 'page' ? (int) $o('page_for_posts', 0) : 0,
            wooPages: array_filter([
                'shop' => (int) $o('woocommerce_shop_page_id', 0),
                'cart' => (int) $o('woocommerce_cart_page_id', 0),
                'checkout' => (int) $o('woocommerce_checkout_page_id', 0),
                'myaccount' => (int) $o('woocommerce_myaccount_page_id', 0),
                'terms' => (int) $o('woocommerce_terms_page_id', 0),
            ]),
            woo: $woo,
            ordersStorage: $hposEnabled && $hposTables ? 'hpos' : 'posts',
            hposSync: $o('woocommerce_custom_orders_table_data_sync_enabled') === 'yes',
            uploadsUrl: $uploadsUrl,
            prefix: $wp->prefix(),
            database: (string) $wp->db()->getDatabaseName(),
            menuLocations: $locations,
        );
    }

    public function hasPlugin(string ...$patterns): bool
    {
        foreach ($this->activePlugins as $plugin) {
            foreach ($patterns as $pattern) {
                if ($plugin === $pattern || fnmatch($pattern, $plugin)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Hosts of siteurl/home (links to them become relative). */
    public function hosts(): array
    {
        return array_values(array_unique(array_filter([
            parse_url($this->siteUrl, PHP_URL_HOST), parse_url($this->homeUrl, PHP_URL_HOST),
        ])));
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** WordPress version from db_version (the schema revision each release ships with; nearest known lower bound). */
    public static function wpVersion(string $dbVersion): ?string
    {
        if (! ctype_digit($dbVersion)) {
            return null;
        }
        $map = [60717 => '6.9', 58975 => '6.7', 57155 => '6.5', 56657 => '6.4', 55853 => '6.3', 53496 => '6.2', 51917 => '6.0',
            49752 => '5.6', 47018 => '5.4', 45805 => '5.3', 44719 => '5.1', 38590 => '4.7'];
        foreach ($map as $revision => $version) {
            if ((int) $dbVersion >= $revision) {
                return $version.'+';
            }
        }

        return '<4.7';
    }

    private static function timezone(string $zone, $offset): string
    {
        if ($zone !== '' && in_array($zone, timezone_identifiers_list(), true)) {
            return $zone;
        }
        if (is_numeric($offset) && (float) $offset !== 0.0) {
            $minutes = (int) round((float) $offset * 60);

            return sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);
        }

        return 'UTC';
    }
}
