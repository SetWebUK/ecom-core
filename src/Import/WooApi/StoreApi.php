<?php

namespace Pine\Commerce\Import\WooApi;

/**
 * The public WooCommerce Store API (wc/store/v1 – no key needed, "public catalogue only" mode) converted to the
 * wc/v3 shapes ApiMap reads. It only shows what a shop visitor sees: published, visible products, their categories,
 * global attributes and variations (prices in minor units, stock as in-stock / out-of-stock / backorder only).
 * No customers, orders, coupons, private products, stock quantities, cost or SEO fields.
 */
final class StoreApi
{
    /** Store API product → wc/v3 product array. */
    public static function product(array $s): array
    {
        $prices = (array) ($s['prices'] ?? []);
        $money = self::money($prices);
        $onSale = ! empty($s['on_sale']);
        $attributes = [];
        foreach (array_values((array) ($s['attributes'] ?? [])) as $i => $a) {
            if (! is_array($a)) {
                continue;
            }
            $taxonomy = (string) ($a['taxonomy'] ?? '');
            $attributes[] = [
                'id' => str_starts_with($taxonomy, 'pa_') ? (int) ($a['id'] ?? 0) : 0,
                'name' => (string) ($a['name'] ?? ''),
                'slug' => $taxonomy,
                'position' => $i,
                'visible' => true,
                'variation' => ! empty($a['has_variations']),
                'options' => array_map(fn ($t) => (string) ($t['name'] ?? ''), array_values(array_filter((array) ($a['terms'] ?? []), 'is_array'))),
                'terms' => array_values(array_filter((array) ($a['terms'] ?? []), 'is_array')),
            ];
        }
        $stock = ! empty($s['is_in_stock']) ? (! empty($s['is_on_backorder']) ? 'onbackorder' : 'instock') : 'outofstock';

        return [
            'id' => (int) $s['id'],
            'name' => (string) ($s['name'] ?? ''),
            'slug' => (string) ($s['slug'] ?? ''),
            'permalink' => (string) ($s['permalink'] ?? ''),
            'type' => (string) ($s['type'] ?? 'simple'),
            'status' => 'publish',
            'featured' => false,
            'description' => (string) ($s['description'] ?? ''),
            'short_description' => (string) ($s['short_description'] ?? ''),
            'sku' => (string) ($s['sku'] ?? ''),
            'price' => $money($prices['price'] ?? null),
            'regular_price' => $money($prices['regular_price'] ?? null),
            'sale_price' => $onSale ? $money($prices['sale_price'] ?? null) : '',
            'manage_stock' => false,
            'stock_quantity' => null,
            'stock_status' => $stock,
            'backorders' => ! empty($s['is_on_backorder']) ? 'notify' : 'no',
            'sold_individually' => ! empty($s['sold_individually']),
            'weight' => (string) ($s['weight'] ?? ''),
            'dimensions' => (array) ($s['dimensions'] ?? []),
            'average_rating' => (string) ($s['average_rating'] ?? '0'),
            'rating_count' => (int) ($s['review_count'] ?? 0),
            'categories' => array_values(array_filter((array) ($s['categories'] ?? []), 'is_array')),
            'tags' => array_values(array_filter((array) ($s['tags'] ?? []), 'is_array')),
            'images' => array_values(array_filter((array) ($s['images'] ?? []), 'is_array')),
            'attributes' => $attributes,
            'grouped_products' => array_map('intval', (array) ($s['grouped_products'] ?? [])),
            'store_variations' => array_values(array_filter((array) ($s['variations'] ?? []), 'is_array')),
            'meta_data' => [],
        ];
    }

    /**
     * Store API variation (products/{id} of a variation) → wc/v3 variation array; its attribute values come from the
     * parent's variation list ([label => term slug]) and are turned back into option names.
     *
     * @param  array<string,string>  $values  attribute label => term slug (parent's "variations" entry)
     * @param  list<array>  $parentAttributes  the converted parent's attributes
     */
    public static function variation(array $s, array $values, array $parentAttributes, int $position): array
    {
        $prices = (array) ($s['prices'] ?? []);
        $money = self::money($prices);
        $attributes = [];
        foreach ($values as $label => $termSlug) {
            foreach ($parentAttributes as $a) {
                if (strcasecmp((string) $a['name'], (string) $label) === 0) {
                    $name = $termSlug;
                    foreach ($a['terms'] ?? [] as $t) {
                        if ((string) ($t['slug'] ?? '') === (string) $termSlug) {
                            $name = (string) $t['name'];
                        }
                    }
                    $attributes[] = ['id' => $a['id'], 'name' => $a['name'], 'slug' => $a['slug'], 'option' => $name];
                }
            }
        }

        return [
            'id' => (int) $s['id'],
            'status' => 'publish',
            'menu_order' => $position,
            'sku' => (string) ($s['sku'] ?? ''),
            'regular_price' => $money($prices['regular_price'] ?? null),
            'sale_price' => ! empty($s['on_sale']) ? $money($prices['sale_price'] ?? null) : '',
            'manage_stock' => false,
            'stock_quantity' => null,
            'stock_status' => ! empty($s['is_in_stock']) ? (! empty($s['is_on_backorder']) ? 'onbackorder' : 'instock') : 'outofstock',
            'weight' => (string) ($s['weight'] ?? ''),
            'attributes' => $attributes,
            'image' => is_array($s['images'][0] ?? null) ? $s['images'][0] : null,
        ];
    }

    /** Store API category → wc/v3 category array (+ its permalink). */
    public static function category(array $c): array
    {
        return [
            'id' => (int) $c['id'], 'name' => (string) ($c['name'] ?? ''), 'slug' => (string) ($c['slug'] ?? ''), 'parent' => (int) ($c['parent'] ?? 0),
            'description' => (string) ($c['description'] ?? ''), 'menu_order' => 0, 'count' => (int) ($c['count'] ?? 0),
            'image' => is_array($c['image'] ?? null) ? $c['image'] : null, 'link' => (string) ($c['permalink'] ?? ''),
        ];
    }

    /** @return callable(mixed):string minor units ("3999") → decimal string ("39.99") */
    private static function money(array $prices): callable
    {
        $unit = max(0, (int) ($prices['currency_minor_unit'] ?? 2));

        return fn ($v) => $v === null || $v === '' || ! is_numeric($v) ? '' : number_format(((int) $v) / (10 ** $unit), $unit, '.', '');
    }
}
