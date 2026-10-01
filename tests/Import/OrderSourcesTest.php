<?php

namespace Pine\Commerce\Tests\Import;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Data\WcOrder;
use Pine\Commerce\Import\Orders\HposOrderSource;
use Pine\Commerce\Import\Orders\LegacyPostsOrderSource;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Tests\TestCase;

/**
 * ARCHITECTURE.md §14 step 14: the same three orders (line items, variation, shipping, coupon + fee lines, a refund)
 * stored the legacy way (shop_order posts + postmeta) and in HPOS tables must come out as identical WcOrder DTOs.
 */
class OrderSourcesTest extends TestCase
{
    private const ORDERS = [
        101 => [
            'status' => 'wc-processing', 'currency' => 'GBP', 'created' => '2025-02-01 09:00:00', 'modified' => '2025-02-01 09:05:00',
            'totals' => ['discount' => 0, 'discount_tax' => 0, 'shipping' => 4.99, 'shipping_tax' => 1.00, 'tax' => 20.00, 'total' => 125.99],
            'billing' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'company' => '', 'address_1' => '1 High St', 'address_2' => '', 'city' => 'Leeds',
                'state' => '', 'postcode' => 'LS1 1AA', 'country' => 'GB', 'email' => 'ann@example.com', 'phone' => '0113'],
            'shipping' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'company' => '', 'address_1' => '1 High St', 'address_2' => '', 'city' => 'Leeds',
                'state' => '', 'postcode' => 'LS1 1AA', 'country' => 'GB', 'email' => '', 'phone' => ''],
            'payment' => ['stripe', 'Card', 'pi_123'], 'customer' => 7, 'note' => 'Leave at door', 'via' => 'checkout', 'key' => 'wc_order_abc',
            'paid' => '2025-02-01 09:01:00', 'completed' => null, 'ip' => '1.2.3.4', 'ua' => 'Firefox', 'incl' => false,
            'meta' => ['_order_number' => '5001', '_wc_order_attribution_utm_source' => 'google', '_order_version' => '9.9.0', '_prices_include_tax' => 'no'],
            'items' => [
                [1001, 'line_item', 'Shirt', ['_product_id' => 50, '_variation_id' => 0, '_qty' => 1, '_line_subtotal' => 100, '_line_total' => 100, '_line_tax' => 20]],
                [1002, 'line_item', 'Charger - 65W', ['_product_id' => 60, '_variation_id' => 61, '_qty' => 2, '_line_subtotal' => 0, '_line_total' => 0, '_line_tax' => 0, 'pa_watts' => '65w']],
                [1003, 'shipping', 'Next day', ['method_id' => 'flat_rate', 'cost' => 4.99, 'total_tax' => 1.00]],
            ],
        ],
        102 => [
            'status' => 'wc-completed', 'currency' => 'EUR', 'created' => '2025-03-01 12:00:00', 'modified' => '2025-03-05 12:00:00',
            'totals' => ['discount' => 10, 'discount_tax' => 2, 'shipping' => 0, 'shipping_tax' => 0, 'tax' => 18.00, 'total' => 110.00],
            'billing' => ['first_name' => 'Bo', 'last_name' => 'Ng', 'company' => 'Ng Ltd', 'address_1' => '2 Low Rd', 'address_2' => 'Flat 3', 'city' => 'York',
                'state' => 'NYK', 'postcode' => 'YO1 1AA', 'country' => 'GB', 'email' => 'BO@Example.com', 'phone' => ''],
            'shipping' => ['first_name' => '', 'last_name' => '', 'company' => '', 'address_1' => '', 'address_2' => '', 'city' => '',
                'state' => '', 'postcode' => '', 'country' => '', 'email' => '', 'phone' => ''],
            'payment' => ['paypal', 'PayPal', 'PP-9'], 'customer' => 0, 'note' => '', 'via' => 'admin', 'key' => 'wc_order_def',
            'paid' => '2025-03-01 12:01:00', 'completed' => '2025-03-02 08:00:00', 'ip' => '', 'ua' => '', 'incl' => true,
            'meta' => ['_order_number' => '5002', '_prices_include_tax' => 'yes', '_order_version' => '9.9.0'],
            'items' => [
                [1004, 'line_item', 'Bag', ['_product_id' => 70, '_variation_id' => 0, '_qty' => 1, '_line_subtotal' => 100, '_line_total' => 90, '_line_tax' => 18]],
                [1005, 'coupon', 'save10', ['discount_amount' => 10, 'discount_amount_tax' => 2]],
                [1006, 'fee', 'Gift wrap', ['_line_total' => 2, '_line_tax' => 0]],
            ],
        ],
        104 => [
            'status' => 'wc-pending', 'currency' => 'GBP', 'created' => '2025-04-01 00:00:00', 'modified' => '2025-04-01 00:00:00',
            'totals' => ['discount' => 0, 'discount_tax' => 0, 'shipping' => 0, 'shipping_tax' => 0, 'tax' => 0, 'total' => 0],
            'billing' => ['first_name' => '', 'last_name' => '', 'company' => '', 'address_1' => '', 'address_2' => '', 'city' => '',
                'state' => '', 'postcode' => '', 'country' => '', 'email' => 'guest@example.com', 'phone' => ''],
            'shipping' => ['first_name' => '', 'last_name' => '', 'company' => '', 'address_1' => '', 'address_2' => '', 'city' => '',
                'state' => '', 'postcode' => '', 'country' => '', 'email' => '', 'phone' => ''],
            'payment' => ['', '', ''], 'customer' => 0, 'note' => '', 'via' => 'checkout', 'key' => 'wc_order_ghi',
            'paid' => null, 'completed' => null, 'ip' => '', 'ua' => '', 'incl' => false,
            'meta' => ['_order_version' => '9.9.0', '_prices_include_tax' => 'no'],
            'items' => [],
        ],
    ];

    /** refund 103 of order 102: 1 x Bag */
    private const REFUND = ['id' => 103, 'parent' => 102, 'amount' => 108, 'reason' => 'Damaged', 'by' => 1,
        'created' => '2025-03-05 11:00:00', 'modified' => '2025-03-05 11:00:00',
        'items' => [[1007, 'line_item', 'Bag', ['_product_id' => 70, '_qty' => -1, '_line_total' => -90, '_line_tax' => -18, '_refunded_item_id' => 1004]]]];

    private function seedItems(): void
    {
        $db = DB::connection(WpFixture::CONNECTION);
        foreach (self::ORDERS + [103 => ['items' => self::REFUND['items']]] as $orderId => $o) {
            foreach ($o['items'] as [$id, $type, $name, $meta]) {
                $db->table('woocommerce_order_items')->insert(['order_item_id' => $id, 'order_item_name' => $name, 'order_item_type' => $type, 'order_id' => $orderId]);
                foreach ($meta as $k => $v) {
                    $db->table('woocommerce_order_itemmeta')->insert(['order_item_id' => $id, 'meta_key' => $k, 'meta_value' => (string) $v]);
                }
            }
        }
        // a note (read by the orders step from comments in both storages)
        $db->table('comments')->insert(['comment_post_ID' => 101, 'comment_author' => 'WooCommerce', 'comment_type' => 'order_note', 'comment_content' => 'Paid']);
    }

    private function seedPosts(): void
    {
        $db = DB::connection(WpFixture::CONNECTION);
        foreach (self::ORDERS as $id => $o) {
            $meta = $o['meta'] + [
                '_order_currency' => $o['currency'], '_cart_discount' => $o['totals']['discount'], '_cart_discount_tax' => $o['totals']['discount_tax'],
                '_order_shipping' => $o['totals']['shipping'], '_order_shipping_tax' => $o['totals']['shipping_tax'], '_order_tax' => $o['totals']['tax'],
                '_order_total' => $o['totals']['total'], '_payment_method' => $o['payment'][0], '_payment_method_title' => $o['payment'][1],
                '_transaction_id' => $o['payment'][2], '_customer_user' => $o['customer'], '_created_via' => $o['via'], '_order_key' => $o['key'],
                '_date_paid' => $o['paid'] ? strtotime($o['paid'].' UTC') : '', '_date_completed' => $o['completed'] ? strtotime($o['completed'].' UTC') : '',
                '_customer_ip_address' => $o['ip'], '_customer_user_agent' => $o['ua'], '_recorded_sales' => 'yes', '_cart_hash' => 'x',
            ];
            foreach (['billing', 'shipping'] as $type) {
                foreach ($o[$type] as $f => $v) {
                    $meta['_'.$type.'_'.$f] = $v;
                }
            }
            $db->table('posts')->insert(['ID' => $id, 'post_type' => 'shop_order', 'post_status' => $o['status'], 'post_excerpt' => $o['note'],
                'post_date_gmt' => $o['created'], 'post_modified_gmt' => $o['modified'], 'post_title' => 'Order']);
            foreach ($meta as $k => $v) {
                $db->table('postmeta')->insert(['post_id' => $id, 'meta_key' => $k, 'meta_value' => (string) $v]);
            }
        }
        $r = self::REFUND;
        $db->table('posts')->insert(['ID' => $r['id'], 'post_type' => 'shop_order_refund', 'post_status' => 'wc-completed', 'post_parent' => $r['parent'],
            'post_date_gmt' => $r['created'], 'post_modified_gmt' => $r['modified'], 'post_excerpt' => $r['reason']]);
        foreach (['_refund_amount' => $r['amount'], '_refund_reason' => $r['reason'], '_refunded_by' => $r['by'], '_order_total' => -$r['amount']] as $k => $v) {
            $db->table('postmeta')->insert(['post_id' => $r['id'], 'meta_key' => $k, 'meta_value' => (string) $v]);
        }
        // HPOS placeholder rows must never be read in HPOS mode (and are not shop_order posts in posts mode)
        $db->table('posts')->insert(['ID' => 900, 'post_type' => 'shop_order_placehold', 'post_status' => 'draft']);
    }

    private function seedHpos(): void
    {
        $db = DB::connection(WpFixture::CONNECTION);
        foreach (self::ORDERS as $id => $o) {
            $t = $o['totals'];
            $db->table('wc_orders')->insert(['id' => $id, 'status' => $o['status'], 'currency' => $o['currency'], 'type' => 'shop_order',
                'tax_amount' => $t['tax'] + $t['shipping_tax'], 'total_amount' => $t['total'], 'customer_id' => $o['customer'],
                'billing_email' => $o['billing']['email'], 'date_created_gmt' => $o['created'], 'date_updated_gmt' => $o['modified'],
                'parent_order_id' => 0, 'payment_method' => $o['payment'][0], 'payment_method_title' => $o['payment'][1],
                'transaction_id' => $o['payment'][2], 'ip_address' => $o['ip'], 'user_agent' => $o['ua'], 'customer_note' => $o['note']]);
            foreach (['billing', 'shipping'] as $type) {
                if (array_filter($o[$type])) {
                    $db->table('wc_order_addresses')->insert(['order_id' => $id, 'address_type' => $type] + $o[$type]);
                }
            }
            $db->table('wc_order_operational_data')->insert(['order_id' => $id, 'created_via' => $o['via'], 'woocommerce_version' => '9.9.0',
                'prices_include_tax' => $o['incl'], 'order_key' => $o['key'], 'date_paid_gmt' => $o['paid'], 'date_completed_gmt' => $o['completed'],
                'shipping_tax_amount' => $t['shipping_tax'], 'shipping_total_amount' => $t['shipping'], 'discount_tax_amount' => $t['discount_tax'],
                'discount_total_amount' => $t['discount']]);
            foreach (array_diff_key($o['meta'], ['_order_version' => 1, '_prices_include_tax' => 1]) as $k => $v) {
                $db->table('wc_orders_meta')->insert(['order_id' => $id, 'meta_key' => $k, 'meta_value' => $v]);
            }
        }
        $r = self::REFUND;
        $db->table('wc_orders')->insert(['id' => $r['id'], 'status' => 'wc-completed', 'type' => 'shop_order_refund', 'currency' => 'EUR',
            'total_amount' => -$r['amount'], 'tax_amount' => 0, 'parent_order_id' => $r['parent'], 'date_created_gmt' => $r['created'],
            'date_updated_gmt' => $r['modified'], 'customer_note' => $r['reason']]);
        foreach (['_refund_amount' => $r['amount'], '_refund_reason' => $r['reason'], '_refunded_by' => $r['by']] as $k => $v) {
            $db->table('wc_orders_meta')->insert(['order_id' => $r['id'], 'meta_key' => $k, 'meta_value' => (string) $v]);
        }
    }

    private static function flatten(iterable $chunks): array
    {
        $out = [];
        foreach ($chunks as $chunk) {
            foreach ($chunk as $order) {
                $out[] = self::toArray($order);
            }
        }

        return $out;
    }

    private static function toArray(object $o): array
    {
        $a = get_object_vars($o);
        foreach ($a['items'] ?? [] as $i => $item) {
            $a['items'][$i] = get_object_vars($item);
        }
        ksort($a['meta']);

        return $a;
    }

    public function test_hpos_and_posts_storage_yield_identical_orders_and_refunds(): void
    {
        WpFixture::boot();
        $this->seedItems();
        $this->seedPosts();
        $this->seedHpos();
        $wp = new WordPressSource(WpFixture::CONNECTION);

        $posts = new LegacyPostsOrderSource($wp, 'Europe/London');
        $hpos = new HposOrderSource($wp, 'Europe/London');

        $this->assertSame(3, $posts->count());
        $this->assertSame(3, $hpos->count());
        $this->assertSame(1, $posts->refundCount());
        $this->assertSame(1, $hpos->refundCount());

        $fromPosts = self::flatten($posts->orders(2)); // chunk size 2 → two chunks
        $fromHpos = self::flatten($hpos->orders(2));
        $this->assertCount(3, $fromPosts);
        $this->assertEquals($fromPosts, $fromHpos);

        $first = $fromPosts[0];
        $this->assertSame(101, $first['id']);
        $this->assertSame('processing', $first['status']);
        $this->assertEqualsWithDelta(20.0, $first['totals']['tax'], 0.0001); // cart tax only; shipping tax separate
        $this->assertEqualsWithDelta(1.0, $first['totals']['shippingTax'], 0.0001);
        $this->assertSame('ann@example.com', $first['billing']['email']);
        $this->assertSame('2025-02-01 09:01:00', $first['datePaidGmt']);
        $this->assertSame(['_order_number', '_order_version', '_prices_include_tax', '_wc_order_attribution_utm_source'], array_keys($first['meta']));
        $this->assertSame(['line_item', 'line_item', 'shipping'], array_column($first['items'], 'type'));
        $this->assertSame(61, $first['items'][1]['variationId']);
        $this->assertSame(['coupon', 'fee'], array_slice(array_column($fromPosts[1]['items'], 'type'), 1));
        $this->assertTrue($fromPosts[1]['pricesIncludeTax']);

        $refundsPosts = array_map(fn ($r) => self::toArrayRefund($r), $posts->refunds([101, 102, 104]));
        $refundsHpos = array_map(fn ($r) => self::toArrayRefund($r), $hpos->refunds([101, 102, 104]));
        $this->assertEquals($refundsPosts, $refundsHpos);
        $this->assertSame(108.0, $refundsPosts[0]['amount']);
        $this->assertSame(102, $refundsPosts[0]['parentId']);
        $this->assertSame('1004', (string) $refundsPosts[0]['items'][0]['meta']['_refunded_item_id']);
    }

    private static function toArrayRefund(object $r): array
    {
        $a = get_object_vars($r);
        foreach ($a['items'] as $i => $item) {
            $a['items'][$i] = get_object_vars($item);
        }

        return $a;
    }

    public function test_site_profile_picks_the_storage_from_the_hpos_option(): void
    {
        WpFixture::boot();
        $wp = new WordPressSource(WpFixture::CONNECTION);
        WpFixture::option('siteurl', 'https://shop.test');
        WpFixture::option('woocommerce_custom_orders_table_enabled', 'no');
        $this->assertSame('posts', SiteProfile::detect($wp)->ordersStorage);

        WpFixture::option('woocommerce_custom_orders_table_enabled', 'yes');
        WpFixture::option('woocommerce_custom_orders_table_data_sync_enabled', 'yes');
        $wp = new WordPressSource(WpFixture::CONNECTION); // option cache
        $profile = SiteProfile::detect($wp);
        $this->assertSame('hpos', $profile->ordersStorage);
        $this->assertTrue($profile->hposSync);
    }

    public function test_number_comes_from_the_dto_meta_via_the_sequential_numbers_adapter(): void
    {
        $order = new WcOrder(1, 'processing', 'GBP', false, [], [], [], '', '', '', 0, '', '', '', null, null, null, null, '', '', ['_order_number' => '77', '_order_number_formatted' => 'ORD-77']);
        $this->assertSame('ORD-77', (new \Pine\Commerce\Import\Adapters\SequentialOrderNumbers)->orderNumber($order));
        $plain = new WcOrder(2, 'processing', 'GBP', false, [], [], [], '', '', '', 0, '', '', '', null, null, null, null, '', '', []);
        $this->assertNull((new \Pine\Commerce\Import\Adapters\SequentialOrderNumbers)->orderNumber($plain));
    }
}
