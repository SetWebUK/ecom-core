<?php

namespace Pine\Commerce\Import\Mapping;

use Illuminate\Support\Str;
use Pine\Commerce\Import\Data\SeoData;
use Pine\Commerce\Import\Data\WpProduct;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * The `products` / `product_variations` rows of a WooCommerce product, built from the product in WordPress form
 * (WpProduct: the post + its meta keys `_sku`, `_regular_price`, `_stock` …). Shared by both importers: the
 * database importer reads the meta from the database, the REST API importer (Import\WooApi) converts the API's
 * JSON into the same meta keys first – so prices, stock, sale dates, dimensions and statuses map identically.
 */
final class ProductRows
{
    /**
     * @param  array{slug:string, primary_category_id:?int, breadcrumb_category_id:?int, seo:?SeoData, featured:bool,
     *               short_description?:?string, description?:?string}  $resolved  values the caller worked out (URL
     *               category, unique slug, SEO, featured flag; descriptions when they are already HTML)
     */
    public static function row(WpProduct $product, array $resolved, string $now): array
    {
        $post = $product->post;
        $m = $product->meta;
        $type = $product->type;
        $regular = WordPressSource::decimal($m['_regular_price'] ?? null);
        $sale = WordPressSource::decimal($m['_sale_price'] ?? null);
        $saleFrom = WordPressSource::ts($m['_sale_price_dates_from'] ?? null);
        $saleTo = WordPressSource::ts($m['_sale_price_dates_to'] ?? null);
        $manage = WordPressSource::yes($m['_manage_stock'] ?? 'no');
        $stock = isset($m['_stock']) && is_numeric($m['_stock']) ? (int) $m['_stock'] : null;
        $seo = $resolved['seo'] ?? null;

        return [
            'wp_id' => $post->id,
            'name' => Formatter::decode($post->title),
            'slug' => $resolved['slug'],
            'sku' => trim((string) ($m['_sku'] ?? '')) ?: null,
            'type' => $type === 'variable' ? 'variable' : 'simple',
            'status' => self::status($post->status),
            'primary_category_id' => $resolved['primary_category_id'] ?? null,
            'breadcrumb_category_id' => $resolved['breadcrumb_category_id'] ?? null,
            'short_description' => array_key_exists('short_description', $resolved) ? $resolved['short_description'] : Formatter::clean(Formatter::autop($post->excerpt)),
            'description' => array_key_exists('description', $resolved) ? $resolved['description'] : Formatter::clean(Formatter::autop($post->content)),
            'subtitle' => null,
            'condition' => null,
            'brand' => null,
            'regular_price' => $regular,
            'sale_price' => $sale,
            'sale_starts_at' => $saleFrom,
            'sale_ends_at' => $saleTo,
            'price' => self::effectivePrice($regular, $sale, $saleFrom, $saleTo),
            'cost_price' => null,
            'manage_stock' => $manage,
            'stock_quantity' => $manage ? $stock : null,
            'stock_status' => in_array($m['_stock_status'] ?? '', ['instock', 'outofstock', 'onbackorder'], true) ? $m['_stock_status'] : 'instock',
            'backorders' => in_array($m['_backorders'] ?? '', ['no', 'notify', 'yes'], true) ? $m['_backorders'] : 'no',
            'low_stock_threshold' => isset($m['_low_stock_amount']) && is_numeric($m['_low_stock_amount']) ? (int) $m['_low_stock_amount'] : null,
            'sold_individually' => WordPressSource::yes($m['_sold_individually'] ?? 'no'),
            'weight' => self::dimension($m['_weight'] ?? null),
            'length' => self::dimension($m['_length'] ?? null),
            'width' => self::dimension($m['_width'] ?? null),
            'height' => self::dimension($m['_height'] ?? null),
            'tax_status' => ($m['_tax_status'] ?? '') !== '' ? $m['_tax_status'] : 'taxable',
            'tax_class' => ($m['_tax_class'] ?? '') !== '' ? $m['_tax_class'] : null,
            'is_featured' => (bool) ($resolved['featured'] ?? false),
            'sort_order' => $post->menuOrder,
            'total_sales' => max(0, (int) ($m['total_sales'] ?? 0)),
            'average_rating' => round((float) ($m['_wc_average_rating'] ?? 0), 2),
            'review_count' => (int) ($m['_wc_review_count'] ?? 0),
            'meta_title' => Str::limit((string) $seo?->title, 250, '') ?: null,
            'meta_description' => $seo?->description,
            'focus_keyword' => Str::limit((string) $seo?->focusKeyword, 250, '') ?: null,
            'gtin' => trim((string) ($m['_global_unique_id'] ?? '')) ?: null,
            'published_at' => $post->status === 'publish' ? WordPressSource::gmt($post->dateGmt) : null,
            'created_at' => WordPressSource::gmt($post->dateGmt) ?? WordPressSource::gmt($post->modifiedGmt) ?? $now,
            'updated_at' => WordPressSource::gmt($post->modifiedGmt) ?? $now,
            'deleted_at' => null,
        ];
    }

    /**
     * A `product_variations` row from a variation in WordPress form: post fields + meta (`attribute_pa_*` options,
     * `_sku`, prices, stock, `_weight`).
     *
     * @param  array{wp_id:int, product_id:int, status:string, menu_order:int, date_gmt:?string, modified_gmt:?string, meta:array, image:?string}  $v
     */
    public static function variationRow(array $v, string $now): array
    {
        $m = $v['meta'];
        $options = [];
        foreach ($m as $key => $value) {
            if (str_starts_with((string) $key, 'attribute_')) {
                $slug = str_starts_with($key, 'attribute_pa_') ? substr($key, 13) : Str::slug(substr($key, 10));
                $options[$slug] = $value;
            }
        }
        $manage = WordPressSource::yes($m['_manage_stock'] ?? 'no');
        $stock = isset($m['_stock']) && is_numeric($m['_stock']) ? (int) $m['_stock'] : null;

        return [
            'wp_id' => $v['wp_id'],
            'product_id' => $v['product_id'],
            'sku' => trim((string) ($m['_sku'] ?? '')) ?: null,
            'options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'regular_price' => WordPressSource::decimal($m['_regular_price'] ?? null),
            'sale_price' => WordPressSource::decimal($m['_sale_price'] ?? null),
            'manage_stock' => $manage,
            'stock_quantity' => $manage ? $stock : null,
            'stock_status' => $manage && $stock !== null ? ($stock > 0 ? 'instock' : 'outofstock') : ($m['_stock_status'] ?? 'instock'),
            'image' => $v['image'],
            'weight' => is_numeric($m['_weight'] ?? null) && (float) $m['_weight'] > 0 ? (float) $m['_weight'] : null,
            'is_active' => $v['status'] === 'publish',
            'sort_order' => (int) $v['menu_order'],
            'created_at' => WordPressSource::gmt($v['date_gmt']) ?? $now,
            'updated_at' => WordPressSource::gmt($v['modified_gmt']) ?? $now,
        ];
    }

    /**
     * sort_order = position within the product (by WordPress menu_order, then id), starting at 0 – WooCommerce lists a
     * product's variations that way and menu_order is often 0 for all of them.
     *
     * @param  list<array{wp_id:int, product_id:int, sort_order:int}>  $rows
     * @return list<array>
     */
    public static function normaliseVariationOrder(array $rows): array
    {
        $byProduct = [];
        foreach ($rows as $i => $row) {
            $byProduct[$row['product_id']][] = $i;
        }
        foreach ($byProduct as $indexes) {
            usort($indexes, fn ($a, $b) => [(int) $rows[$a]['sort_order'], (int) $rows[$a]['wp_id']] <=> [(int) $rows[$b]['sort_order'], (int) $rows[$b]['wp_id']]);
            foreach ($indexes as $position => $i) {
                $rows[$i]['sort_order'] = $position;
            }
        }

        return $rows;
    }

    /** WordPress post status -> products.status. */
    public static function status(string $postStatus): string
    {
        return match ($postStatus) {
            'publish' => 'published',
            'private' => 'private',
            default => 'draft',
        };
    }

    /** A slug unique within this run ("name", "name-2" …). */
    public static function uniqueSlug(string $slug, array &$used): string
    {
        $base = $slug;
        $i = 2;
        while (isset($used[$slug])) {
            $slug = $base.'-'.$i++;
        }
        $used[$slug] = true;

        return $slug;
    }

    /** Same rule as Product::isOnSale(). */
    public static function effectivePrice(?float $regular, ?float $sale, ?string $from, ?string $to): ?float
    {
        $now = gmdate('Y-m-d H:i:s');
        $onSale = $sale !== null && $regular !== null && $sale < $regular
            && (! $from || $from <= $now) && (! $to || $to >= $now);

        return $onSale ? $sale : $regular;
    }

    public static function dimension($value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /** The warning both importers give for product types the schema does not have. */
    public static function typeWarning(int $id, string $name, string $type, ?string $externalUrl): ?string
    {
        if ($type !== 'external' && $type !== 'grouped') {
            return null;
        }

        return "Product #{$id} '$name' is a $type product – imported as a simple product"
            .($type === 'external' ? ' (external URL: '.($externalUrl ?: '?').')' : ' (children linked as related products)');
    }
}
