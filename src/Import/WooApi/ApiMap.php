<?php

namespace Pine\Commerce\Import\WooApi;

use Illuminate\Support\Str;
use Pine\Commerce\Import\Data\WcOrder;
use Pine\Commerce\Import\Data\WcOrderItem;
use Pine\Commerce\Import\Data\WcRefund;
use Pine\Commerce\Import\Data\WpPost;
use Pine\Commerce\Import\Data\WpProduct;
use Pine\Commerce\Import\Data\WpTerm;

/**
 * WooCommerce REST API (wc/v3) JSON → the importer's WordPress-shaped data (Data\WpProduct with `_sku`/`_price`…
 * meta, Data\WcOrder, Data\WcRefund, Data\WpTerm), so Import\Mapping builds exactly the rows the database importer
 * builds. Pure functions – no HTTP, no database.
 */
final class ApiMap
{
    /** ISO 8601 GMT ("2025-01-01T10:00:00") → "Y-m-d H:i:s" (null for empty/zero). */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || str_starts_with($value, '0000')) {
            return null;
        }
        $ts = strtotime(str_ends_with($value, 'Z') || preg_match('/[+-]\d\d:?\d\d$/', $value) ? $value : $value.' UTC');

        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    /** ISO GMT → unix timestamp string (WooCommerce's meta format for sale dates / coupon expiry). */
    public static function timestamp(mixed $value): string
    {
        $date = self::date($value);

        return $date ? (string) strtotime($date.' UTC') : '';
    }

    public static function yesNo(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? 'yes' : 'no';
    }

    /** meta_data list → [key => value] (first value per key, like the database importer). */
    public static function meta(array $metaData): array
    {
        $out = [];
        foreach ($metaData as $m) {
            if (is_array($m) && isset($m['key']) && is_string($m['key']) && ! array_key_exists($m['key'], $out)) {
                $out[$m['key']] = $m['value'] ?? null;
            }
        }

        return $out;
    }

    /** Path of a URL relative to the site's home ("https://shop/a/b/" with home "https://shop" → "a/b"). */
    public static function path(?string $url, string $home): ?string
    {
        if (! $url) {
            return null;
        }
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $base = rtrim((string) parse_url($home, PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($path, $base.'/')) {
            $path = substr($path, strlen($base));
        }
        if (parse_url($url, PHP_URL_QUERY)) {
            return null; // plain permalinks (?p=123 / ?product=x) – no path to keep
        }

        return trim($path, '/');
    }

    /**
     * A product (wc/v3 products/{id}) in WordPress form.
     *
     * @param  array<int,string>  $attributeSlugs  remote attribute id => slug without "pa_" (older WooCommerce sends no slug)
     */
    public static function product(array $p, array $attributeSlugs = []): WpProduct
    {
        $id = (int) $p['id'];
        $meta = self::meta((array) ($p['meta_data'] ?? []));
        $dims = (array) ($p['dimensions'] ?? []);
        $meta = [
            '_sku' => (string) ($p['sku'] ?? ''),
            '_regular_price' => (string) ($p['regular_price'] ?? ''),
            '_sale_price' => (string) ($p['sale_price'] ?? ''),
            '_price' => (string) ($p['price'] ?? ''),
            '_sale_price_dates_from' => self::timestamp($p['date_on_sale_from_gmt'] ?? null),
            '_sale_price_dates_to' => self::timestamp($p['date_on_sale_to_gmt'] ?? null),
            '_manage_stock' => ($p['manage_stock'] ?? false) === true ? 'yes' : 'no',
            '_stock' => isset($p['stock_quantity']) && is_numeric($p['stock_quantity']) ? (string) $p['stock_quantity'] : '',
            '_stock_status' => (string) ($p['stock_status'] ?? 'instock'),
            '_backorders' => (string) ($p['backorders'] ?? 'no'),
            '_low_stock_amount' => isset($p['low_stock_amount']) && is_numeric($p['low_stock_amount']) ? (string) $p['low_stock_amount'] : '',
            '_sold_individually' => self::yesNo($p['sold_individually'] ?? false),
            '_weight' => (string) ($p['weight'] ?? ''),
            '_length' => (string) ($dims['length'] ?? ''),
            '_width' => (string) ($dims['width'] ?? ''),
            '_height' => (string) ($dims['height'] ?? ''),
            '_tax_status' => (string) ($p['tax_status'] ?? 'taxable'),
            '_tax_class' => (string) ($p['tax_class'] ?? ''),
            'total_sales' => (string) ($p['total_sales'] ?? 0),
            '_wc_average_rating' => (string) ($p['average_rating'] ?? 0),
            '_wc_review_count' => (string) ($p['rating_count'] ?? 0),
            '_global_unique_id' => (string) ($p['global_unique_id'] ?? ''),
            '_product_url' => (string) ($p['external_url'] ?? ''),
            '_product_attributes' => self::productAttributes((array) ($p['attributes'] ?? []), $attributeSlugs),
            '_upsell_ids' => array_map('intval', (array) ($p['upsell_ids'] ?? [])),
            '_crosssell_ids' => array_map('intval', (array) ($p['cross_sell_ids'] ?? [])),
            '_children' => array_map('intval', (array) ($p['grouped_products'] ?? [])),
        ] + $meta;

        $post = new WpPost($id, 'product', (string) ($p['status'] ?? 'publish'), (string) ($p['name'] ?? ''), (string) ($p['slug'] ?? ''),
            (string) ($p['description'] ?? ''), (string) ($p['short_description'] ?? ''), (int) ($p['parent_id'] ?? 0), (int) ($p['menu_order'] ?? 0),
            self::date($p['date_created_gmt'] ?? null), self::date($p['date_modified_gmt'] ?? null), 0, self::date($p['date_created'] ?? null));
        $terms = ['product_cat' => array_map(fn ($c) => new WpTerm((int) $c['id'], 'product_cat', (string) ($c['name'] ?? ''), (string) ($c['slug'] ?? '')),
            array_values(array_filter((array) ($p['categories'] ?? []), 'is_array')))];
        foreach ($meta['_product_attributes'] as $key => $attr) {
            if (! empty($attr['is_taxonomy'])) {
                $terms[$key] = array_map(fn ($name) => new WpTerm(0, $key, (string) $name, Str::slug((string) $name)), $attr['options']);
            }
        }

        return new WpProduct($post, $meta, $terms, array_map(fn ($c) => (int) $c['id'], array_values(array_filter((array) ($p['categories'] ?? []), 'is_array'))),
            $meta['_product_attributes'], (string) ($p['type'] ?? 'simple'));
    }

    /**
     * wc/v3 product attributes → `_product_attributes` form: taxonomy attributes keyed "pa_{slug}", local ones by their
     * sanitised name; each keeps its options (term names / values).
     */
    public static function productAttributes(array $attributes, array $attributeSlugs = []): array
    {
        $out = [];
        foreach ($attributes as $i => $a) {
            if (! is_array($a)) {
                continue;
            }
            $remoteId = (int) ($a['id'] ?? 0);
            $options = array_values(array_map(fn ($o) => html_entity_decode((string) $o, ENT_QUOTES | ENT_HTML5, 'UTF-8'), (array) ($a['options'] ?? [])));
            $common = ['position' => (int) ($a['position'] ?? $i), 'is_visible' => ! empty($a['visible']) ? 1 : 0,
                'is_variation' => ! empty($a['variation']) ? 1 : 0, 'options' => $options, 'remote_id' => $remoteId,
                'label' => html_entity_decode((string) ($a['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')];
            if ($remoteId > 0) {
                $slug = self::attributeSlug($a, $attributeSlugs);
                $out['pa_'.$slug] = ['name' => 'pa_'.$slug, 'value' => '', 'is_taxonomy' => 1] + $common;
            } else {
                $name = html_entity_decode((string) ($a['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($name === '') {
                    continue;
                }
                $out[Str::slug($name)] = ['name' => $name, 'value' => implode(' | ', $options), 'is_taxonomy' => 0] + $common;
            }
        }

        return $out;
    }

    /** "pa_colour" → "colour"; from the attribute's slug, else the attribute list, else its name. */
    public static function attributeSlug(array $a, array $attributeSlugs = []): string
    {
        $slug = (string) ($a['slug'] ?? '');
        if ($slug === '' && isset($attributeSlugs[(int) ($a['id'] ?? 0)])) {
            return $attributeSlugs[(int) $a['id']];
        }
        if ($slug === '') {
            $slug = Str::slug((string) ($a['name'] ?? ''));
        }

        return str_starts_with($slug, 'pa_') ? substr($slug, 3) : $slug;
    }

    /**
     * A variation (wc/v3 products/{id}/variations) as WordPress post fields + meta for Mapping\ProductRows::variationRow().
     *
     * @param  callable(string $attributeSlug, string $optionName): string  $termSlug  term name → slug
     * @return array{wp_id:int, status:string, menu_order:int, date_gmt:?string, modified_gmt:?string, meta:array, image:?array}
     */
    public static function variation(array $v, callable $termSlug, array $attributeSlugs = []): array
    {
        $meta = [
            '_sku' => (string) ($v['sku'] ?? ''),
            '_regular_price' => (string) ($v['regular_price'] ?? ''),
            '_sale_price' => (string) ($v['sale_price'] ?? ''),
            '_manage_stock' => ($v['manage_stock'] ?? false) === true ? 'yes' : 'no', // "parent" = the parent product manages stock
            '_stock' => isset($v['stock_quantity']) && is_numeric($v['stock_quantity']) ? (string) $v['stock_quantity'] : '',
            '_stock_status' => (string) ($v['stock_status'] ?? 'instock'),
            '_weight' => (string) ($v['weight'] ?? ''),
        ];
        foreach ((array) ($v['attributes'] ?? []) as $a) {
            if (! is_array($a)) {
                continue;
            }
            $option = html_entity_decode((string) ($a['option'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ((int) ($a['id'] ?? 0) > 0) {
                $slug = self::attributeSlug($a, $attributeSlugs);
                $meta['attribute_pa_'.$slug] = $option === '' ? '' : $termSlug($slug, $option);
            } else {
                $meta['attribute_'.Str::slug((string) ($a['slug'] ?? $a['name'] ?? ''))] = $option;
            }
        }

        return [
            'wp_id' => (int) $v['id'],
            'status' => (string) ($v['status'] ?? 'publish'),
            'menu_order' => (int) ($v['menu_order'] ?? 0),
            'date_gmt' => self::date($v['date_created_gmt'] ?? null),
            'modified_gmt' => self::date($v['date_modified_gmt'] ?? null),
            'meta' => $meta,
            'image' => is_array($v['image'] ?? null) && ! empty($v['image']['src']) ? $v['image'] : null,
            'shipping_class_id' => (int) ($v['shipping_class_id'] ?? 0),
        ];
    }

    /** A product category / tag / attribute term as a WpTerm (meta: order, thumbnail src). */
    public static function term(array $t, string $taxonomy): WpTerm
    {
        return new WpTerm((int) $t['id'], $taxonomy, (string) ($t['name'] ?? ''), (string) ($t['slug'] ?? ''), (int) ($t['parent'] ?? 0),
            (string) ($t['description'] ?? ''), (int) ($t['menu_order'] ?? 0), []);
    }

    /** An order (wc/v3 orders) as a WcOrder with its items in WooCommerce's order-item meta form. */
    public static function order(array $o): WcOrder
    {
        $meta = self::meta((array) ($o['meta_data'] ?? []));
        $meta['_order_version'] ??= (string) ($o['version'] ?? '');
        $meta['_prices_include_tax'] ??= ! empty($o['prices_include_tax']) ? 'yes' : 'no';
        $address = function (array $a): array {
            $out = [];
            foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $f) {
                $out[$f] = (string) ($a[$f] ?? '');
            }

            return $out;
        };
        $order = new WcOrder(
            id: (int) $o['id'],
            status: (string) ($o['status'] ?? 'pending'),
            currency: (string) ($o['currency'] ?? ''),
            pricesIncludeTax: ! empty($o['prices_include_tax']),
            totals: [
                'subtotal' => null,
                'discount' => (float) ($o['discount_total'] ?? 0),
                'discountTax' => (float) ($o['discount_tax'] ?? 0),
                'shipping' => (float) ($o['shipping_total'] ?? 0),
                'shippingTax' => (float) ($o['shipping_tax'] ?? 0),
                'tax' => (float) ($o['cart_tax'] ?? 0),
                'total' => (float) ($o['total'] ?? 0),
            ],
            billing: $address((array) ($o['billing'] ?? [])),
            shipping: $address((array) ($o['shipping'] ?? [])),
            paymentMethod: (string) ($o['payment_method'] ?? ''),
            paymentMethodTitle: (string) ($o['payment_method_title'] ?? ''),
            transactionId: (string) ($o['transaction_id'] ?? ''),
            customerId: (int) ($o['customer_id'] ?? 0),
            customerNote: (string) ($o['customer_note'] ?? ''),
            createdVia: (string) ($o['created_via'] ?? ''),
            orderKey: (string) ($o['order_key'] ?? ''),
            dateCreatedGmt: self::date($o['date_created_gmt'] ?? null),
            dateModifiedGmt: self::date($o['date_modified_gmt'] ?? null),
            datePaidGmt: self::date($o['date_paid_gmt'] ?? null),
            dateCompletedGmt: self::date($o['date_completed_gmt'] ?? null),
            ipAddress: (string) ($o['customer_ip_address'] ?? ''),
            userAgent: (string) ($o['customer_user_agent'] ?? ''),
            meta: $meta,
        );
        $order->number = trim((string) ($o['number'] ?? '')) ?: (string) $o['id'];
        $order->items = self::orderItems($o);

        return $order;
    }

    /** @return list<WcOrderItem> */
    public static function orderItems(array $o): array
    {
        $items = [];
        foreach ((array) ($o['line_items'] ?? []) as $li) {
            $m = [];
            foreach ((array) ($li['meta_data'] ?? []) as $md) {
                // variation options (pa_colour => red) and custom item meta; scalar values only
                if (is_array($md) && isset($md['key']) && is_scalar($md['value'] ?? null) && ! array_key_exists($md['key'], $m)) {
                    $m[(string) $md['key']] = (string) $md['value'];
                }
            }
            $m = [
                '_product_id' => (int) ($li['product_id'] ?? 0), '_variation_id' => (int) ($li['variation_id'] ?? 0), '_qty' => (int) ($li['quantity'] ?? 0),
                '_line_subtotal' => (float) ($li['subtotal'] ?? 0), '_line_subtotal_tax' => (float) ($li['subtotal_tax'] ?? 0),
                '_line_total' => (float) ($li['total'] ?? 0), '_line_tax' => (float) ($li['total_tax'] ?? 0), '_sku' => (string) ($li['sku'] ?? ''),
            ] + $m;
            $items[] = new WcOrderItem((int) $li['id'], 'line_item', (string) ($li['name'] ?? ''), (int) ($li['product_id'] ?? 0),
                (int) ($li['variation_id'] ?? 0), (int) ($li['quantity'] ?? 0), (float) ($li['subtotal'] ?? 0), (float) ($li['total'] ?? 0), (float) ($li['total_tax'] ?? 0), $m);
        }
        foreach ((array) ($o['shipping_lines'] ?? []) as $s) {
            $items[] = new WcOrderItem((int) $s['id'], 'shipping', (string) ($s['method_title'] ?? ''), 0, 0, 0, (float) ($s['total'] ?? 0), (float) ($s['total'] ?? 0),
                (float) ($s['total_tax'] ?? 0), ['method_id' => (string) ($s['method_id'] ?? ''), 'instance_id' => (string) ($s['instance_id'] ?? ''),
                    'cost' => (float) ($s['total'] ?? 0), 'total_tax' => (float) ($s['total_tax'] ?? 0)]);
        }
        foreach ((array) ($o['fee_lines'] ?? []) as $f) {
            $items[] = new WcOrderItem((int) $f['id'], 'fee', (string) ($f['name'] ?? ''), 0, 0, 0, (float) ($f['total'] ?? 0), (float) ($f['total'] ?? 0),
                (float) ($f['total_tax'] ?? 0), ['_line_total' => (float) ($f['total'] ?? 0), '_line_tax' => (float) ($f['total_tax'] ?? 0)]);
        }
        foreach ((array) ($o['coupon_lines'] ?? []) as $c) {
            $items[] = new WcOrderItem((int) $c['id'], 'coupon', (string) ($c['code'] ?? ''), 0, 0, 0, (float) ($c['discount'] ?? 0), (float) ($c['discount'] ?? 0),
                (float) ($c['discount_tax'] ?? 0), ['discount_amount' => (float) ($c['discount'] ?? 0), 'discount_amount_tax' => (float) ($c['discount_tax'] ?? 0)]);
        }
        foreach ((array) ($o['tax_lines'] ?? []) as $t) {
            $tax = (float) ($t['tax_total'] ?? 0);
            $shippingTax = (float) ($t['shipping_tax_total'] ?? 0);
            $items[] = new WcOrderItem((int) $t['id'], 'tax', (string) ($t['rate_code'] ?? ''), 0, 0, 0, 0.0, $tax + $shippingTax, 0.0, [
                'rate_id' => (int) ($t['rate_id'] ?? 0), 'label' => (string) ($t['label'] ?? ''), 'compound' => ! empty($t['compound']) ? '1' : '',
                'tax_amount' => $tax, 'shipping_tax_amount' => $shippingTax, 'rate_percent' => (float) ($t['rate_percent'] ?? 0),
            ]);
        }
        usort($items, fn (WcOrderItem $a, WcOrderItem $b) => $a->id <=> $b->id);

        return $items;
    }

    /** A refund (wc/v3 orders/{id}/refunds). Refunded lines point at the order line through `_refunded_item_id`. */
    public static function refund(array $r, int $orderId, array $orderLines = []): WcRefund
    {
        $refund = new WcRefund((int) $r['id'], $orderId, round(abs((float) ($r['amount'] ?? 0)), 2), (string) ($r['reason'] ?? ''),
            (int) ($r['refunded_by'] ?? 0), self::date($r['date_created_gmt'] ?? null), self::date($r['date_created_gmt'] ?? null));
        foreach ((array) ($r['line_items'] ?? []) as $li) {
            $meta = self::meta((array) ($li['meta_data'] ?? []));
            $refundedItem = (int) ($meta['_refunded_item_id'] ?? 0);
            if (! $refundedItem) {
                // older WooCommerce hides the key: the order line with the same product / variation
                foreach ($orderLines as $line) {
                    if ((int) ($line['product_id'] ?? 0) === (int) ($li['product_id'] ?? 0) && (int) ($line['variation_id'] ?? 0) === (int) ($li['variation_id'] ?? 0)) {
                        $refundedItem = (int) $line['id'];
                        break;
                    }
                }
            }
            $refund->items[] = new WcOrderItem((int) $li['id'], 'line_item', (string) ($li['name'] ?? ''), (int) ($li['product_id'] ?? 0),
                (int) ($li['variation_id'] ?? 0), (int) ($li['quantity'] ?? 0), (float) ($li['subtotal'] ?? 0), (float) ($li['total'] ?? 0),
                (float) ($li['total_tax'] ?? 0), ['_qty' => (int) ($li['quantity'] ?? 0), '_line_total' => (float) ($li['total'] ?? 0),
                    '_line_tax' => (float) ($li['total_tax'] ?? 0), '_refunded_item_id' => $refundedItem, '_product_id' => (int) ($li['product_id'] ?? 0)]);
        }

        return $refund;
    }

    /** A coupon (wc/v3 coupons) as shop_coupon meta for Mapping\CatalogRows::coupon(). */
    public static function couponMeta(array $c): array
    {
        return [
            'discount_type' => (string) ($c['discount_type'] ?? 'fixed_cart'),
            'coupon_amount' => (string) ($c['amount'] ?? '0'),
            'free_shipping' => self::yesNo($c['free_shipping'] ?? false),
            'minimum_amount' => (string) ($c['minimum_amount'] ?? ''),
            'maximum_amount' => (string) ($c['maximum_amount'] ?? ''),
            'individual_use' => self::yesNo($c['individual_use'] ?? false),
            'exclude_sale_items' => self::yesNo($c['exclude_sale_items'] ?? false),
            'product_ids' => array_map('intval', (array) ($c['product_ids'] ?? [])),
            'exclude_product_ids' => array_map('intval', (array) ($c['excluded_product_ids'] ?? [])),
            'product_categories' => array_map('intval', (array) ($c['product_categories'] ?? [])),
            'exclude_product_categories' => array_map('intval', (array) ($c['excluded_product_categories'] ?? [])),
            'customer_email' => array_values(array_filter(array_map('strval', (array) ($c['email_restrictions'] ?? [])))),
            'usage_limit' => (string) ($c['usage_limit'] ?? ''),
            'usage_limit_per_user' => (string) ($c['usage_limit_per_user'] ?? ''),
            'usage_count' => (string) ($c['usage_count'] ?? 0),
            'date_expires' => self::timestamp($c['date_expires_gmt'] ?? null),
        ];
    }

    /** Method instance settings ({"cost": {"value": "5"}}) → [key => value] like the stored option. */
    public static function settings(array $settings): array
    {
        $out = [];
        foreach ($settings as $key => $s) {
            $out[(string) $key] = is_array($s) ? ($s['value'] ?? null) : $s;
        }

        return $out;
    }
}
