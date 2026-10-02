<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Contracts\OrderNumberProvider;
use Pine\Commerce\Import\Data\WcOrder;
use Pine\Commerce\Import\Data\WcOrderItem;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * WooCommerce orders from either storage (HPOS tables or legacy posts – ImportContext::orderSource()), in chunks:
 * totals, addresses, payment, attribution, line items (product/variation ids remapped), shipping line, coupon and fee
 * lines (kept in orders.meta – the schema has no columns for them), refunds (+ refunded quantities), order notes and
 * a payment record for every paid order. Order numbers come from an OrderNumberProvider (sequential-number plugins),
 * else the WooCommerce order id.
 */
class OrdersStep extends AbstractStep
{
    public function key(): string
    {
        return 'orders';
    }

    public function section(): string
    {
        return 'orders';
    }

    public function after(): array
    {
        return ['users', 'catalog.variations'];
    }

    /** Order meta copied into orders.meta (JSON) for reference in the admin (`commerce-import.orders.meta_keys` adds more). */
    public const META_KEYS = [
        '_wc_order_attribution_source_type', '_wc_order_attribution_utm_source', '_wc_order_attribution_utm_medium',
        '_wc_order_attribution_utm_campaign', '_wc_order_attribution_utm_term', '_wc_order_attribution_utm_content',
        '_wc_order_attribution_referrer', '_wc_order_attribution_session_entry', '_wc_order_attribution_session_start_time',
        '_wc_order_attribution_session_pages', '_wc_order_attribution_session_count', '_wc_order_attribution_device_type',
        '_card_brand', 'last4', '_intent_id', '_charge_id', '_stripe_customer_id', '_payment_method_id', '_wcpay_mode',
        '_wcpay_transaction_fee', '_wcpay_fraud_outcome_status', '_charge_risk_level', '_ppcp_paypal_order_id',
        '_ppcp_paypal_intent', '_ppcp_paypal_payer_email', '_ppcp_paypal_payment_source', 'super_transaction_id',
        'super_transaction_reference', 'super_checkout_session_id', '_order_version', '_prices_include_tax', 'is_vat_exempt',
    ];

    public const CHUNK = 500;

    protected function clear(): void
    {
        $this->ctx->owned('orders')->delete(); // cascades items, notes, refunds, payments
    }

    protected function import(): void
    {
        $source = $this->ctx->orderSource();
        $numbers = $this->ctx->adapters->providers(OrderNumberProvider::class);
        $metaKeys = array_values(array_unique(array_merge(self::META_KEYS, (array) $this->ctx->config('orders.meta_keys', []))));
        $defaultCurrency = $this->ctx->site->woo['currency'] ?? 'GBP';

        $userMap = $this->userMap();
        $productMap = $this->ctx->map('products');
        $variationMap = $this->ctx->map('product_variations');
        $staffByWpId = $this->ctx->owned('users')->pluck('id', 'wp_id')->all();

        // Attribute names for variation meta (pa_memory -> Memory, 16gb -> 16GB)
        $attrNames = DB::table('attributes')->pluck('name', 'slug')->all();
        $valueNames = [];
        foreach (DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->get(['attributes.slug as a', 'attribute_values.slug as v', 'attribute_values.value']) as $v) {
            $valueNames[$v->a.'|'.$v->v] = $v->value;
        }
        $productSkus = $this->ctx->owned('products')->pluck('sku', 'wp_id')->all();
        $variationSkus = $this->ctx->owned('product_variations')->pluck('sku', 'wp_id')->all();

        // Numbers already used by orders that did not come from WordPress (e.g. test orders)
        $foreignNumbers = DB::table('orders')->whereNull('wp_id')->pluck('id', 'number')->all();

        $orderIds = [];      // wp id => Laravel id
        $itemIdMap = [];     // wp item id => Laravel order_items id
        $statuses = [];
        $wpCount = 0;
        $lineItems = 0;
        $flat = 0;
        $payments = 0;
        $withFees = 0;
        foreach ($source->orders(self::CHUNK) as $chunk) {
            $rows = [];
            $orderItems = [];
            foreach ($chunk as $order) {
                /** @var WcOrder $order */
                $wpCount++;
                $order->number = $this->number($order, $numbers);
                $number = $order->number;
                if (isset($foreignNumbers[$number])) {
                    $this->ctx->warn("Order #$number (WP {$order->id}) skipped – number already used by a non-imported Laravel order id {$foreignNumbers[$number]}");

                    continue;
                }
                $email = $order->billingEmail();
                $userId = $order->customerId > 0 ? ($staffByWpId[$order->customerId] ?? null) : null;
                $userId ??= $userMap[$email] ?? null;

                $lineSubtotal = 0.0;
                $shippingItem = null;
                $coupons = [];
                $couponLines = [];
                $fees = [];
                foreach ($order->items as $item) {
                    /** @var WcOrderItem $item */
                    $im = $item->meta;
                    if ($item->type === 'line_item') {
                        $lineItems++;
                        $lineSubtotal += (float) ($im['_line_subtotal'] ?? 0);
                        $qty = max(1, (int) ($im['_qty'] ?? 1));
                        $options = [];
                        foreach ($im as $k => $v) {
                            if ($k === '' || $k[0] === '_' || is_array(WordPressSource::unserialize($v))) {
                                continue;
                            }
                            $slug = str_starts_with($k, 'pa_') ? substr($k, 3) : $k;
                            $label = $attrNames[$slug] ?? Formatter::decode($k);
                            $options[$label] = $valueNames[$slug.'|'.$v] ?? Formatter::decode($v);
                        }
                        $wpProduct = $item->productId;
                        $wpVariation = $item->variationId;
                        $orderItems[$order->id][$item->id] = [
                            'product_id' => $productMap[$wpProduct] ?? null,
                            'product_variation_id' => $wpVariation ? ($variationMap[$wpVariation] ?? null) : null,
                            'name' => Formatter::decode($item->name),
                            'sku' => ($wpVariation ? ($variationSkus[$wpVariation] ?? null) : null) ?? ($productSkus[$wpProduct] ?? null),
                            'quantity' => $qty,
                            'unit_price' => round((float) ($im['_line_subtotal'] ?? 0) / $qty, 2),
                            'subtotal' => round((float) ($im['_line_subtotal'] ?? 0), 2),
                            'total' => round((float) ($im['_line_total'] ?? 0), 2),
                            'tax' => round((float) ($im['_line_tax'] ?? 0), 2),
                            'refunded_quantity' => 0,
                            'options' => $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                            'created_at' => $order->dateCreatedGmt,
                            'updated_at' => $order->dateModifiedGmt,
                        ];
                    } elseif ($item->type === 'shipping') {
                        $shippingItem = ['id' => $im['method_id'] ?? null, 'title' => Formatter::decode($item->name)];
                    } elseif ($item->type === 'coupon') {
                        $coupons[] = Formatter::decode($item->name);
                        $couponLines[] = ['code' => Formatter::decode($item->name), 'discount' => round($item->total, 2), 'discount_tax' => round($item->tax, 2)];
                    } elseif ($item->type === 'fee') {
                        $fees[] = ['name' => Formatter::decode($item->name), 'total' => round($item->total, 2), 'tax' => round($item->tax, 2)];
                    }
                }

                $extra = ['wp_order_id' => $order->id];
                foreach ($metaKeys as $key) {
                    if (isset($order->meta[$key]) && $order->meta[$key] !== '') {
                        $extra[ltrim(str_replace('_wc_order_attribution_', 'attribution_', $key), '_')] = WordPressSource::unserialize($order->meta[$key]);
                    }
                }
                if ($fees) {
                    $extra['fees'] = $fees; // no fee columns in the schema
                    $withFees++;
                }
                if ($couponLines && array_sum(array_column($couponLines, 'discount')) > 0) {
                    $extra['coupon_lines'] = $couponLines;
                }
                $created = $order->dateCreatedGmt ?? $order->dateModifiedGmt ?? $this->now();
                $m = $order->meta;
                $b = $order->billing;
                $s = $order->shipping;
                $statuses[$order->status] = ($statuses[$order->status] ?? 0) + 1;

                $rows[] = [
                    'wp_id' => $order->id,
                    'number' => $number,
                    'order_key' => Str::limit($order->orderKey ?: 'wc_order_'.Str::random(13), 64, ''),
                    'user_id' => $userId,
                    'status' => $order->status,
                    'currency' => $order->currency ?: $defaultCurrency,
                    'subtotal' => round($lineSubtotal, 2),
                    'discount_total' => round((float) $order->totals['discount'], 2),
                    'shipping_total' => round((float) $order->totals['shipping'], 2),
                    'tax_total' => round((float) $order->totals['tax'] + (float) $order->totals['shippingTax'], 2),
                    'total' => round((float) $order->totals['total'], 2),
                    'refunded_total' => 0,
                    'coupon_code' => $coupons ? implode(', ', $coupons) : null,
                    'shipping_method' => $shippingItem['id'] ?? null,
                    'shipping_method_title' => $shippingItem['title'] ?? null,
                    'payment_method' => $order->paymentMethod ?: null,
                    'payment_method_title' => Formatter::decode($order->paymentMethodTitle) ?: null,
                    'transaction_id' => $order->transactionId ?: null,
                    'email' => $email,
                    'phone' => trim((string) ($b['phone'] ?? '')) ?: null,
                    'billing_first_name' => $this->v($b, 'first_name'),
                    'billing_last_name' => $this->v($b, 'last_name'),
                    'billing_company' => $this->v($b, 'company'),
                    'billing_address_1' => $this->v($b, 'address_1'),
                    'billing_address_2' => $this->v($b, 'address_2'),
                    'billing_city' => $this->v($b, 'city'),
                    'billing_county' => $this->v($b, 'state'),
                    'billing_postcode' => $this->v($b, 'postcode'),
                    'billing_country' => $this->country($b['country'] ?? null),
                    'shipping_first_name' => $this->v($s, 'first_name'),
                    'shipping_last_name' => $this->v($s, 'last_name'),
                    'shipping_company' => $this->v($s, 'company'),
                    'shipping_address_1' => $this->v($s, 'address_1'),
                    'shipping_address_2' => $this->v($s, 'address_2'),
                    'shipping_city' => $this->v($s, 'city'),
                    'shipping_county' => $this->v($s, 'state'),
                    'shipping_postcode' => $this->v($s, 'postcode'),
                    'shipping_country' => $this->country($s['country'] ?? null),
                    'shipping_phone' => $this->v($s, 'phone'),
                    'customer_note' => trim(Formatter::decode($order->customerNote)) ?: null,
                    'ip_address' => $order->ipAddress ?: null,
                    'user_agent' => $order->userAgent ?: null,
                    'source' => ($m['_wc_order_attribution_utm_source'] ?? '') ?: null,
                    'source_type' => ($m['_wc_order_attribution_source_type'] ?? '') ?: null,
                    'created_via' => $order->createdVia ?: 'checkout',
                    'meta' => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'paid_at' => $order->datePaidGmt,
                    'completed_at' => $order->dateCompletedGmt,
                    'created_at' => $created,
                    'updated_at' => $order->dateModifiedGmt ?? $created,
                    'deleted_at' => null,
                ];
            }

            // Imported numbers must stay unique: orders sharing a number with a non-imported order are reported above.
            $chunkIds = $this->ctx->save('orders', $rows, 'wp_id', ['created_at']);
            $orderIds += $chunkIds;
            $ids = array_values($chunkIds);
            foreach (['order_items', 'order_notes', 'refunds'] as $table) {
                foreach (array_chunk($ids, 500) as $part) {
                    DB::table($table)->whereIn('order_id', $part)->delete();
                }
            }
            foreach (array_chunk($ids, 500) as $part) {
                DB::table('payments')->whereIn('order_id', $part)->where('payload->source', 'wordpress')->delete();
            }

            // Line items (one insert each: refunds need the WP item id -> new id map)
            foreach ($orderItems as $wpOrder => $list) {
                if (! isset($chunkIds[$wpOrder])) {
                    continue;
                }
                foreach ($list as $wpItemId => $row) {
                    $itemIdMap[$wpItemId] = DB::table('order_items')->insertGetId(['order_id' => $chunkIds[$wpOrder]] + $row);
                    $flat++;
                }
            }
            $payments += $this->payments($rows, $chunkIds);
        }

        [$refunds, $wpRefunds] = $this->refunds($source, $orderIds, $itemIdMap, $staffByWpId);
        [$notes, $wpNotes] = $this->notes($orderIds);

        $this->ctx->count('Orders', $wpCount.' ('.$source->storage().' storage)', $this->ctx->owned('orders')->count(),
            'statuses: '.collect($statuses)->map(fn ($c, $s) => "$s $c")->implode(', ').($withFees ? "; $withFees with fee lines (orders.meta.fees)" : ''));
        $this->ctx->count('Order line items', $lineItems, $flat);
        $this->ctx->count('Refunds', $wpRefunds, $refunds);
        $this->ctx->count('Order notes', $wpNotes, $notes);
        $this->ctx->count('Payments (paid orders)', '—', $payments);
        $linked = $this->ctx->owned('orders')->whereNotNull('user_id')->count();
        $this->ctx->count('Orders linked to customer accounts', '—', $linked, 'by WP user or billing email');
    }

    /** @param list<OrderNumberProvider> $providers */
    private function number(WcOrder $order, array $providers): string
    {
        foreach ($providers as $provider) {
            if (($n = $provider->orderNumber($order)) !== null && $n !== '') {
                return $n;
            }
        }

        return (string) $order->id;
    }

    /** @return array{0:int,1:int} [imported, source count] */
    private function refunds($source, array $orderIds, array $itemIdMap, array $staffByWpId): array
    {
        $wpRefunds = $source->refundCount();
        $rows = [];
        $refundedQty = [];
        $refundedTotals = [];
        foreach ($source->refunds(array_keys($orderIds)) as $refund) {
            $orderId = $orderIds[$refund->parentId] ?? null;
            if (! $orderId) {
                continue;
            }
            $lines = [];
            foreach ($refund->items as $item) {
                $im = $item->meta;
                $orig = (int) ($im['_refunded_item_id'] ?? 0);
                $newItem = $itemIdMap[$orig] ?? null;
                $qty = abs((int) ($im['_qty'] ?? 0));
                if ($newItem) {
                    $lines[] = ['order_item_id' => $newItem, 'quantity' => $qty, 'amount' => round(abs((float) ($im['_line_total'] ?? 0)) + abs((float) ($im['_line_tax'] ?? 0)), 2)];
                    $refundedQty[$newItem] = ($refundedQty[$newItem] ?? 0) + $qty;
                }
            }
            $refundedTotals[$orderId] = ($refundedTotals[$orderId] ?? 0) + $refund->amount;
            $rows[] = [
                'order_id' => $orderId,
                'user_id' => $staffByWpId[$refund->refundedBy] ?? null,
                'amount' => $refund->amount,
                'reason' => trim(Formatter::decode($refund->reason)) ?: null,
                'items' => $lines ? json_encode($lines) : null,
                'restock' => false,
                'gateway_refund_id' => null,
                'status' => 'completed',
                'created_at' => $refund->dateGmt ?? $this->now(),
                'updated_at' => $refund->modifiedGmt ?? $this->now(),
            ];
        }
        $this->ctx->insert('refunds', $rows);
        foreach ($refundedQty as $itemId => $qty) {
            DB::table('order_items')->where('id', $itemId)->update(['refunded_quantity' => $qty]);
        }
        foreach (array_chunk(array_values($orderIds), 1000) as $chunk) {
            DB::table('orders')->whereIn('id', $chunk)->update(['refunded_total' => 0]);
        }
        foreach ($refundedTotals as $orderId => $total) {
            DB::table('orders')->where('id', $orderId)->update(['refunded_total' => round($total, 2)]);
        }

        return [count($rows), $wpRefunds];
    }

    /** Order notes (comments of type order_note, in comment order) – streamed. @return array{0:int,1:int} */
    private function notes(array $orderIds): array
    {
        $staff = DB::table('users')->whereIn('role', ['admin', 'manager'])->pluck('id', 'email')->all();
        $staffByLogin = [];
        foreach ($this->wp->table('users')->get(['ID', 'user_login', 'display_name', 'user_email']) as $u) {
            $id = $staff[strtolower($u->user_email)] ?? null;
            if ($id) {
                $staffByLogin[$u->user_login] = $staffByLogin[$u->display_name] = $id;
            }
        }
        $customerFlags = $this->wp->table('commentmeta')->where('meta_key', 'is_customer_note')->where('meta_value', '1')
            ->pluck('comment_id')->flip()->all();

        $count = 0;
        $wpCount = 0;
        $rows = [];
        foreach ($this->wp->table('comments')->where('comment_type', 'order_note')->orderBy('comment_ID')->cursor() as $c) {
            $wpCount++;
            $orderId = $orderIds[$c->comment_post_ID] ?? null;
            if (! $orderId) {
                continue;
            }
            $isSystem = in_array($c->comment_author, ['WooCommerce', 'system', ''], true);
            $rows[] = [
                'order_id' => $orderId,
                'user_id' => $isSystem ? null : ($staff[strtolower((string) $c->comment_author_email)] ?? $staffByLogin[$c->comment_author] ?? null),
                'note' => html_entity_decode((string) $c->comment_content, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'is_customer_note' => isset($customerFlags[$c->comment_ID]),
                'is_system' => $isSystem,
                'created_at' => WordPressSource::gmt($c->comment_date_gmt) ?? $this->now(),
                'updated_at' => WordPressSource::gmt($c->comment_date_gmt) ?? $this->now(),
            ];
            if (count($rows) >= 1000) {
                $this->ctx->insert('order_notes', $rows);
                $count += count($rows);
                $rows = [];
            }
        }
        $this->ctx->insert('order_notes', $rows);

        return [$count + count($rows), $wpCount];
    }

    private function payments(array $rows, array $orderIds): int
    {
        $payments = [];
        foreach ($rows as $row) {
            if (! $row['paid_at'] || ! isset($orderIds[$row['wp_id']])) {
                continue;
            }
            $payments[] = [
                'order_id' => $orderIds[$row['wp_id']],
                'gateway' => $row['payment_method'] ?? 'unknown',
                'reference' => $row['transaction_id'],
                'amount' => $row['total'],
                'status' => $row['status'] === 'refunded' ? 'refunded' : 'succeeded',
                'payload' => json_encode(['source' => 'wordpress', 'title' => $row['payment_method_title']], JSON_UNESCAPED_UNICODE),
                'created_at' => $row['paid_at'],
                'updated_at' => $row['paid_at'],
            ];
        }
        $this->ctx->insert('payments', $payments);

        return count($payments);
    }

    /** lower-case email => user id for every account. */
    private function userMap(): array
    {
        $map = [];
        foreach (DB::table('users')->get(['id', 'email']) as $u) {
            $map[strtolower($u->email)] = $u->id;
        }

        return $map;
    }

    private function v(array $a, string $key): ?string
    {
        $value = trim(Formatter::decode($a[$key] ?? ''));

        return $value === '' ? null : Str::limit($value, 250, '');
    }

    private function country(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : substr($code, 0, 2);
    }
}
