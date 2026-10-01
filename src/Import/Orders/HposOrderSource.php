<?php

namespace Pine\Commerce\Import\Orders;

use Illuminate\Support\Str;
use Pine\Commerce\Import\Contracts\OrderSource;
use Pine\Commerce\Import\Data\WcOrder;
use Pine\Commerce\Import\Data\WcRefund;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * Orders stored in WooCommerce's High-Performance Order Storage: {p}wc_orders (+ wc_order_addresses,
 * wc_order_operational_data, wc_orders_meta). Placeholder `shop_order_placehold` posts are never read.
 */
class HposOrderSource implements OrderSource
{
    public function __construct(protected WordPressSource $wp, protected string $timezone = 'UTC') {}

    public function storage(): string
    {
        return 'hpos';
    }

    private function query(string $type)
    {
        return $this->wp->table('wc_orders')->where('type', $type)->whereNotIn('status', ['trash', 'auto-draft', 'wc-checkout-draft']);
    }

    public function count(): int
    {
        return $this->query('shop_order')->count();
    }

    public function refundCount(): int
    {
        return $this->query('shop_order_refund')->count();
    }

    public function orders(int $chunk = 500): iterable
    {
        $ids = $this->query('shop_order')->orderBy('id')->pluck('id')->all();
        foreach (array_chunk($ids, $chunk) as $part) {
            $rows = $this->wp->table('wc_orders')->whereIn('id', $part)->orderBy('id')->get();
            $addresses = [];
            foreach ($this->wp->table('wc_order_addresses')->whereIn('order_id', $part)->get() as $a) {
                $addresses[(int) $a->order_id][$a->address_type] = $a;
            }
            $ops = $this->wp->table('wc_order_operational_data')->whereIn('order_id', $part)->get()->keyBy('order_id');
            $meta = $this->meta($part);
            $items = OrderItems::load($this->wp, $part);
            $out = [];
            foreach ($rows as $row) {
                $order = $this->order($row, $addresses[(int) $row->id] ?? [], $ops->get($row->id), $meta[(int) $row->id] ?? []);
                $order->items = $items[(int) $row->id] ?? [];
                $out[] = $order;
            }
            yield $out;
        }
    }

    public function refunds(array $wpOrderIds): array
    {
        $rows = collect();
        foreach (array_chunk(array_values(array_unique($wpOrderIds)), 1000) as $chunk) {
            $rows = $rows->merge($this->query('shop_order_refund')->whereIn('parent_order_id', $chunk)->get());
        }
        $rows = $rows->sortBy('id')->values();
        $ids = $rows->pluck('id')->map(fn ($v) => (int) $v)->all();
        $meta = $this->meta($ids);
        $items = OrderItems::load($this->wp, $ids);
        $out = [];
        foreach ($rows as $row) {
            $m = $meta[(int) $row->id] ?? [];
            $refund = new WcRefund((int) $row->id, (int) $row->parent_order_id,
                round(abs((float) ($m['_refund_amount'] ?? $row->total_amount ?? 0)), 2),
                (string) ($m['_refund_reason'] ?? $row->customer_note ?? ''), (int) ($m['_refunded_by'] ?? 0),
                WordPressSource::gmt($row->date_created_gmt), WordPressSource::gmt($row->date_updated_gmt));
            $refund->items = $items[(int) $row->id] ?? [];
            $out[] = $refund;
        }

        return $out;
    }

    /** @return array<int,array<string,string>> order id => meta (first value per key) */
    private function meta(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 2000) as $chunk) {
            foreach ($this->wp->table('wc_orders_meta')->whereIn('order_id', $chunk)->orderBy('id')->get(['order_id', 'meta_key', 'meta_value']) as $r) {
                $out[(int) $r->order_id][$r->meta_key] ??= $r->meta_value;
            }
        }

        return $out;
    }

    protected function order(object $row, array $addresses, ?object $op, array $meta): WcOrder
    {
        $address = function (string $type) use ($addresses, $row) {
            $a = [];
            $src = $addresses[$type] ?? null;
            foreach (LegacyPostsOrderSource::ADDRESS_FIELDS as $f) {
                $a[$f] = (string) ($src->{$f} ?? '');
            }
            if ($type === 'billing' && $a['email'] === '' && ! empty($row->billing_email)) {
                $a['email'] = (string) $row->billing_email;
            }

            return $a;
        };
        $shippingTax = (float) ($op->shipping_tax_amount ?? 0);
        // Synthesised so the meta matches the posts storage (WooCommerce keeps these in postmeta there).
        if ($op && ($op->woocommerce_version ?? null) !== null) {
            $meta['_order_version'] ??= (string) $op->woocommerce_version;
        }
        if ($op && ($op->prices_include_tax ?? null) !== null) {
            $meta['_prices_include_tax'] ??= $op->prices_include_tax ? 'yes' : 'no';
        }

        return new WcOrder(
            id: (int) $row->id,
            status: Str::after((string) $row->status, 'wc-'),
            currency: (string) ($row->currency ?? ''),
            pricesIncludeTax: (bool) ($op->prices_include_tax ?? false),
            totals: [
                'subtotal' => null,
                'discount' => (float) ($op->discount_total_amount ?? 0),
                'discountTax' => (float) ($op->discount_tax_amount ?? 0),
                'shipping' => (float) ($op->shipping_total_amount ?? 0),
                'shippingTax' => $shippingTax,
                'tax' => round((float) ($row->tax_amount ?? 0) - $shippingTax, 6),
                'total' => (float) ($row->total_amount ?? 0),
            ],
            billing: $address('billing'),
            shipping: $address('shipping'),
            paymentMethod: (string) ($row->payment_method ?? ''),
            paymentMethodTitle: (string) ($row->payment_method_title ?? ''),
            transactionId: (string) ($row->transaction_id ?? ''),
            customerId: (int) ($row->customer_id ?? 0),
            customerNote: (string) ($row->customer_note ?? ''),
            createdVia: (string) ($op->created_via ?? ''),
            orderKey: (string) ($op->order_key ?? ''),
            dateCreatedGmt: WordPressSource::gmt($row->date_created_gmt ?? null),
            dateModifiedGmt: WordPressSource::gmt($row->date_updated_gmt ?? null),
            datePaidGmt: WordPressSource::gmt($op->date_paid_gmt ?? null),
            dateCompletedGmt: WordPressSource::gmt($op->date_completed_gmt ?? null),
            ipAddress: (string) ($row->ip_address ?? ''),
            userAgent: (string) ($row->user_agent ?? ''),
            meta: $meta,
        );
    }
}
