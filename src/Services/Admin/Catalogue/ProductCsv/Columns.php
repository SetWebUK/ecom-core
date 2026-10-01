<?php

namespace Pine\Commerce\Services\Admin\Catalogue\ProductCsv;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The full product CSV's columns: what the export writes (in this order) and what the import understands.
 *
 * Headers are matched after normalising (lower case, "(units)" dropped, non-alphanumerics → "_"), so "Regular price",
 * "regular_price" and "REGULAR PRICE" are the same column. WooCommerce's own product CSV export headers
 * (Products › Export in WooCommerce) are recognised too, including its "Attribute N name / value(s) / visible" groups.
 *
 * Lists inside one cell are separated by " | " (a literal "|" or "," inside a value is written "\|" / "\,");
 * WooCommerce files use commas instead – both are read.
 */
class Columns
{
    /** key => [label shown in the mapping screen, aliases (normalised WooCommerce / common headers)] */
    public const DEFINITIONS = [
        'id' => ['ID', ['id', 'product_id']],
        'type' => ['Type (simple, variable, variation)', ['type', 'product_type']],
        'sku' => ['SKU', ['sku', 'product_sku', 'variant_sku']],
        'name' => ['Name', ['name', 'title', 'product_name', 'product_title']],
        'slug' => ['URL handle (slug)', ['slug', 'url_handle', 'handle', 'permalink_slug']],
        'status' => ['Status (published, draft, private)', ['status', 'published', 'post_status']],
        'parent_sku' => ['Parent (SKU or id:123)', ['parent_sku', 'parent', 'parent_id']],
        'categories' => ['Categories', ['categories', 'category', 'product_categories']],
        'primary_category' => ['Main category', ['primary_category', 'main_category']],
        'short_description' => ['Short description', ['short_description', 'excerpt', 'summary']],
        'description' => ['Description', ['description', 'long_description', 'content', 'body_html']],
        'regular_price' => ['Regular price', ['regular_price', 'price']],
        'sale_price' => ['Sale price', ['sale_price']],
        'sale_starts_at' => ['Sale starts', ['sale_starts_at', 'date_sale_price_starts', 'sale_price_dates_from', 'sale_start']],
        'sale_ends_at' => ['Sale ends', ['sale_ends_at', 'date_sale_price_ends', 'sale_price_dates_to', 'sale_end']],
        'cost_price' => ['Cost price', ['cost_price', 'cost', 'cost_of_goods', 'meta_cost_price', 'meta_wc_cog_cost']],
        'tax_status' => ['Tax status', ['tax_status']],
        'tax_class' => ['Tax class (variants: parent = same as product)', ['tax_class', 'variant_tax_class', 'variation_tax_class']],
        'manage_stock' => ['Track stock (yes/no)', ['manage_stock', 'track_stock', 'stock_management']],
        'stock_quantity' => ['Stock quantity', ['stock_quantity', 'stock', 'quantity', 'qty', 'stock_qty']],
        'stock_status' => ['Stock status', ['stock_status', 'in_stock']],
        'backorders' => ['Backorders (no, notify, yes)', ['backorders', 'backorders_allowed']],
        'low_stock_threshold' => ['Low stock threshold', ['low_stock_threshold', 'low_stock_amount']],
        'weight' => ['Weight', ['weight']],
        'length' => ['Length', ['length']],
        'width' => ['Width', ['width']],
        'height' => ['Height', ['height']],
        'shipping_class' => ['Shipping class (variants: empty = same as product)', ['shipping_class', 'variant_shipping_class', 'variation_shipping_class']],
        'images' => ['Images (URLs, first = main)', ['images', 'image', 'image_urls', 'image_src']],
        'attributes' => ['Attributes (Name: value, value | …)', ['attributes']],
        'variation_options' => ['Variant options (Name: value | …)', ['variation_options', 'variant_options', 'options']],
        'specs' => ['Specifications (Label: value | …)', ['specs', 'specifications']],
        'meta_title' => ['SEO title', ['meta_title', 'seo_title', 'meta_yoast_wpseo_title', 'meta_rank_math_title']],
        'meta_description' => ['SEO description', ['meta_description', 'seo_description', 'meta_yoast_wpseo_metadesc', 'meta_rank_math_description']],
        'featured' => ['Featured (yes/no)', ['featured', 'is_featured']],
        'condition' => ['Condition', ['condition']],
        'brand' => ['Brand', ['brand', 'brands', 'make']],
        'subtitle' => ['Subtitle', ['subtitle']],
        'gtin' => ['GTIN / EAN / UPC', ['gtin', 'ean', 'upc', 'gtin_upc_ean_or_isbn', 'barcode']],
        'mpn' => ['MPN', ['mpn']],
    ];

    /** Columns the export writes, in order (shipping_class / condition / brand only when available – see exportKeys()). */
    public static function exportKeys(): array
    {
        return array_values(array_filter(array_keys(self::DEFINITIONS), fn (string $key) => static::available($key)));
    }

    /** Is this column part of this shop (feature switch / schema)? */
    public static function available(string $key): bool
    {
        return match ($key) {
            'condition' => commerce_feature('product_condition', false),
            'brand' => commerce_feature('product_brand', false),
            'shipping_class' => static::hasShippingClass(),
            default => true,
        };
    }

    /** schema lookups per database connection (PDO object id) */
    protected static array $modes = [];

    /** Forget the schema lookups (after a migration in the same process). */
    public static function flush(): void
    {
        static::$modes = [];
    }

    /** Only when the shop has shipping classes (see shippingClassMode()). */
    public static function hasShippingClass(): bool
    {
        return static::shippingClassMode() !== null;
    }

    /**
     * How products store their shipping class in this schema: 'id' (products.shipping_class_id → shipping_classes,
     * the core shipping-classes tables), 'string' (a products.shipping_class column) or null (no shipping classes).
     */
    public static function shippingClassMode(): ?string
    {
        try {
            $mode = static::$modes[spl_object_id(\Illuminate\Support\Facades\DB::connection()->getPdo())] ??= match (true) {
                Schema::hasColumn('products', 'shipping_class_id') && Schema::hasTable('shipping_classes') => 'id',
                Schema::hasColumn('products', 'shipping_class') => 'string',
                default => false,
            };

            return $mode ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function label(string $key): string
    {
        if (preg_match('/^woo_attr_(name|values|visible):(\d+)$/', $key, $m)) {
            return 'WooCommerce attribute '.$m[2].' '.['name' => 'name', 'values' => 'value(s)', 'visible' => 'visible'][$m[1]];
        }

        return self::DEFINITIONS[$key][0] ?? $key;
    }

    /** "Weight (kg)" → "weight", "Is featured?" → "is_featured", "Meta: _yoast_wpseo_title" → "meta_yoast_wpseo_title". */
    public static function normalise(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $header = mb_strtolower(trim($header));
        $header = preg_replace('/\s*\([^)]*\)\s*$/u', '', $header);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $header), '_');
    }

    /**
     * Suggested mapping for a header row: [column index => target key|null]. Each target is used once (first match).
     * WooCommerce "Attribute N name/value(s)/visible" headers map to woo_attr_*:N.
     *
     * @param  list<string>  $headers
     * @return array<int,?string>
     */
    public static function autoMap(array $headers): array
    {
        $mapping = [];
        $used = [];
        foreach ($headers as $index => $header) {
            $normal = static::normalise((string) $header);
            $target = null;
            if (preg_match('/^attribute_(\d+)_(name|value_s|values|value|visible)$/', $normal, $m)) {
                $target = 'woo_attr_'.['name' => 'name', 'value_s' => 'values', 'values' => 'values', 'value' => 'values', 'visible' => 'visible'][$m[2]].':'.$m[1];
            } elseif (preg_match('/^attribute_\d+_(global|default)$/', $normal)) {
                $target = null;
            } else {
                foreach (self::DEFINITIONS as $key => [, $aliases]) {
                    if (in_array($normal, $aliases, true) && static::available($key)) {
                        $target = $key;
                        break;
                    }
                }
            }
            if ($target !== null && isset($used[$target])) {
                $target = null;
            }
            if ($target !== null) {
                $used[$target] = true;
            }
            $mapping[$index] = $target;
        }

        return $mapping;
    }

    /** Is this a WooCommerce product export? (its tell-tale headers) */
    public static function looksLikeWooCommerce(array $headers): bool
    {
        $normal = array_map(fn ($h) => static::normalise((string) $h), $headers);

        return (bool) array_intersect($normal, ['in_stock', 'is_featured', 'visibility_in_catalog', 'attribute_1_name', 'backorders_allowed', 'date_sale_price_starts']);
    }

    /** Targets offered in the mapping screen: [key => label] (+ Woo attribute groups present in the file). */
    public static function targets(array $headers = []): array
    {
        $targets = [];
        foreach (self::DEFINITIONS as $key => [$label]) {
            if (static::available($key)) {
                $targets[$key] = $label;
            }
        }
        foreach (static::autoMap($headers) as $target) {
            if ($target && str_starts_with($target, 'woo_attr_')) {
                $targets[$target] = static::label($target);
            }
        }

        return $targets;
    }

    /** Is the key a valid mapping target? */
    public static function isTarget(string $key): bool
    {
        return (isset(self::DEFINITIONS[$key]) && static::available($key)) || preg_match('/^woo_attr_(name|values|visible):\d{1,3}$/', $key) === 1;
    }
}
