<?php

namespace Pine\Commerce\Import\Orders;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Contracts\OrderSource;
use Pine\Commerce\Import\Data\WcOrder;
use Pine\Commerce\Import\Data\WcRefund;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * Orders stored as `shop_order` posts + postmeta (WooCommerce before HPOS, or HPOS off). Core order fields are mapped
 * into the WcOrder DTO and removed from `meta`, so the DTO is identical to what HposOrderSource yields.
 */
class LegacyPostsOrderSource implements OrderSource
{
    public const ADDRESS_FIELDS = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'];

    /** postmeta keys mapped to WcOrder fields (not repeated in WcOrder::$meta). */
    public const CORE_META = ['_order_currency', '_cart_discount', '_cart_discount_tax', '_order_shipping', '_order_shipping_tax',
        '_order_tax', '_order_total', '_payment_method', '_payment_method_title', '_transaction_id', '_customer_user',
        '_created_via', '_order_key', '_date_paid', '_paid_date', '_date_completed', '_completed_date', '_customer_ip_address',
        '_customer_user_agent', '_billing_address_index', '_shipping_address_index', '_recorded_sales',
        '_recorded_coupon_usage_counts', '_order_stock_reduced', '_new_order_email_sent', '_download_permissions_granted', '_cart_hash'];

    public function __construct(protected WordPressSource $wp, protected string $timezone = 'UTC') {}

    public function storage(): string
    {
        return 'posts';
    }

    public function count(): int
    {
        return $this->wp->countPosts('shop_order');
    }

    public function refundCount(): int
    {
        return $this->wp->countPosts('shop_order_refund');
    }

    public function orders(int $chunk = 500): iterable
    {
        $ids = $this->wp->table('posts')->where('post_type', 'shop_order')->whereNotIn('post_status', ['trash', 'auto-draft'])
            ->orderBy('ID')->pluck('ID')->all();
        foreach (array_chunk($ids, $chunk) as $part) {
            $posts = $this->wp->table('posts')->whereIn('ID', $part)->orderBy('ID')->get();
            $meta = $this->wp->postMeta($part);
            $items = OrderItems::load($this->wp, $part);
            $out = [];
            foreach ($posts as $post) {
                $order = $this->order($post, $meta[$post->ID] ?? []);
                $order->items = $items[$post->ID] ?? [];
                $out[] = $order;
            }
            yield $out;
        }
    }

    public function refunds(array $wpOrderIds): array
    {
        $posts = collect();
        foreach (array_chunk(array_values(array_unique($wpOrderIds)), 1000) as $chunk) {
            $posts = $posts->merge($this->wp->table('posts')->where('post_type', 'shop_order_refund')
                ->whereNotIn('post_status', ['trash', 'auto-draft'])->whereIn('post_parent', $chunk)->get());
        }
        $posts = $posts->sortBy('ID')->values();
        $ids = $posts->pluck('ID')->all();
        $meta = $this->wp->postMeta($ids);
        $items = OrderItems::load($this->wp, $ids);
        $out = [];
        foreach ($posts as $post) {
            $m = $meta[$post->ID] ?? [];
            $refund = new WcRefund((int) $post->ID, (int) $post->post_parent,
                round(abs((float) ($m['_refund_amount'] ?? $m['_order_total'] ?? 0)), 2),
                (string) ($m['_refund_reason'] ?? $post->post_excerpt), (int) ($m['_refunded_by'] ?? 0),
                WordPressSource::gmt($post->post_date_gmt), WordPressSource::gmt($post->post_modified_gmt));
            $refund->items = $items[$post->ID] ?? [];
            $out[] = $refund;
        }

        return $out;
    }

    protected function order(object $post, array $m): WcOrder
    {
        $address = function (string $type) use ($m) {
            $a = [];
            foreach (self::ADDRESS_FIELDS as $f) {
                $a[$f] = (string) ($m['_'.$type.'_'.$f] ?? '');
            }

            return $a;
        };
        $meta = array_diff_key($m, array_flip(self::CORE_META));
        foreach (self::ADDRESS_FIELDS as $f) {
            unset($meta['_billing_'.$f], $meta['_shipping_'.$f]);
        }

        return new WcOrder(
            id: (int) $post->ID,
            status: Str::after((string) $post->post_status, 'wc-'),
            currency: (string) ($m['_order_currency'] ?? ''),
            pricesIncludeTax: WordPressSource::yes($m['_prices_include_tax'] ?? 'no'),
            totals: [
                'subtotal' => null,
                'discount' => (float) ($m['_cart_discount'] ?? 0),
                'discountTax' => (float) ($m['_cart_discount_tax'] ?? 0),
                'shipping' => (float) ($m['_order_shipping'] ?? 0),
                'shippingTax' => (float) ($m['_order_shipping_tax'] ?? 0),
                'tax' => (float) ($m['_order_tax'] ?? 0),
                'total' => (float) ($m['_order_total'] ?? 0),
            ],
            billing: $address('billing'),
            shipping: $address('shipping'),
            paymentMethod: (string) ($m['_payment_method'] ?? ''),
            paymentMethodTitle: (string) ($m['_payment_method_title'] ?? ''),
            transactionId: (string) ($m['_transaction_id'] ?? ''),
            customerId: (int) ($m['_customer_user'] ?? 0),
            customerNote: (string) $post->post_excerpt,
            createdVia: (string) ($m['_created_via'] ?? ''),
            orderKey: (string) ($m['_order_key'] ?? ''),
            dateCreatedGmt: WordPressSource::gmt($post->post_date_gmt),
            dateModifiedGmt: WordPressSource::gmt($post->post_modified_gmt),
            datePaidGmt: WordPressSource::ts($m['_date_paid'] ?? null) ?? $this->localDate($m['_paid_date'] ?? null),
            dateCompletedGmt: WordPressSource::ts($m['_date_completed'] ?? null) ?? $this->localDate($m['_completed_date'] ?? null),
            ipAddress: (string) ($m['_customer_ip_address'] ?? ''),
            userAgent: (string) ($m['_customer_user_agent'] ?? ''),
            meta: $meta,
        );
    }

    /** WooCommerce "_paid_date" style local (site timezone) datetime -> UTC. */
    protected function localDate(?string $value): ?string
    {
        if (! $value || str_starts_with($value, '0000')) {
            return null;
        }
        try {
            return Carbon::parse($value, $this->timezone)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
