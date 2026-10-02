<?php

namespace Pine\Commerce\Import\Mapping;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Data\WcOrder;
use Pine\Commerce\Import\Data\WcOrderItem;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\Support\Upserter;

/**
 * A WooCommerce order (Data\WcOrder, whatever it was read from – HPOS tables, legacy posts or the REST API) as the
 * rows of `orders`, `order_items` and `order_tax_lines`: totals, addresses, payment, attribution meta, line items
 * with their variation options, shipping line, coupon and fee lines (kept in orders.meta – the schema has no columns
 * for them) and the per-rate tax lines. Shared by both importers; OrderWriter stores the result.
 */
final class OrderRows
{
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

    /**
     * @param  array<int|string,int>  $productMap  remote product id => products.id
     * @param  array<int|string,int>  $variationMap  remote variation id => product_variations.id
     * @param  array<string,int>  $userMap  lower-case email => users.id
     * @param  array<int|string,int>  $staffByWpId  remote user id => users.id
     * @param  array<string,string>  $attrNames  attribute slug => name
     * @param  array<string,string>  $valueNames  "attributeSlug|valueSlug" => value
     * @param  array<string,int>  $foreignNumbers  order numbers used by orders of another source / created here => orders.id
     * @param  array<int|string,int>  $taxRateMap  remote tax rate id => tax_rates.id
     */
    public function __construct(
        public array $productMap,
        public array $variationMap,
        public array $productSkus,
        public array $variationSkus,
        public array $userMap,
        public array $staffByWpId,
        public array $attrNames,
        public array $valueNames,
        public array $foreignNumbers,
        public array $metaKeys = self::META_KEYS,
        public string $defaultCurrency = 'GBP',
        public array $taxRateMap = [],
    ) {}

    /** Every lookup from the target database (ids of the given import source). */
    public static function lookups(Upserter $u, array $extraMetaKeys = [], string $defaultCurrency = 'GBP'): self
    {
        $attrNames = DB::table('attributes')->pluck('name', 'slug')->all();
        $valueNames = [];
        foreach (DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->get(['attributes.slug as a', 'attribute_values.slug as v', 'attribute_values.value']) as $v) {
            $valueNames[$v->a.'|'.$v->v] = $v->value;
        }
        $userMap = [];
        foreach (DB::table('users')->get(['id', 'email']) as $user) {
            $userMap[strtolower((string) $user->email)] = $user->id;
        }
        // numbers already used by orders that did not come from this source (hand-made test orders, another shop)
        $foreign = DB::table('orders')->where(function ($q) use ($u) {
            $q->whereNull('wp_id');
            if (Upserter::scoped('orders')) {
                $u->source === null ? $q->orWhereNotNull('import_source') : $q->orWhereNull('import_source')->orWhere('import_source', '!=', $u->source);
            }
        })->pluck('id', 'number')->all();

        return new self(
            productMap: $u->map('products'),
            variationMap: $u->map('product_variations'),
            productSkus: $u->owned('products')->pluck('sku', 'wp_id')->all(),
            variationSkus: $u->owned('product_variations')->pluck('sku', 'wp_id')->all(),
            userMap: $userMap,
            staffByWpId: $u->map('users'),
            attrNames: $attrNames,
            valueNames: $valueNames,
            foreignNumbers: $foreign,
            metaKeys: array_values(array_unique(array_merge(self::META_KEYS, $extraMetaKeys))),
            defaultCurrency: $defaultCurrency,
            taxRateMap: $u->map('tax_rates'),
        );
    }

    /**
     * Map one order. `$order->number` must be set. Returns null (+ the reason) when its number belongs to an order
     * of another source.
     *
     * @return array{row:array, items:array<int|string,array>, tax_lines:list<array>, line_items:int, fees:bool}|string
     */
    public function map(WcOrder $order, string $now): array|string
    {
        $number = (string) ($order->number ?? $order->id);
        if (isset($this->foreignNumbers[$number])) {
            return "Order #$number (remote id {$order->id}) skipped – number already used by order id {$this->foreignNumbers[$number]} that was not imported from this shop";
        }
        $email = $order->billingEmail();
        $userId = $order->customerId > 0 ? ($this->staffByWpId[$order->customerId] ?? null) : null;
        $userId ??= $this->userMap[$email] ?? null;

        $lineSubtotal = 0.0;
        $lineItems = 0;
        $shippingItem = null;
        $coupons = [];
        $couponLines = [];
        $fees = [];
        $items = [];
        $taxLines = [];
        foreach ($order->items as $item) {
            /** @var WcOrderItem $item */
            $im = $item->meta;
            if ($item->type === 'line_item') {
                $lineItems++;
                $lineSubtotal += (float) ($im['_line_subtotal'] ?? 0);
                $qty = max(1, (int) ($im['_qty'] ?? 1));
                $options = [];
                foreach ($im as $k => $v) {
                    if ($k === '' || $k[0] === '_' || is_array($v) || is_array(WordPressSource::unserialize($v))) {
                        continue;
                    }
                    $slug = str_starts_with($k, 'pa_') ? substr($k, 3) : $k;
                    $label = $this->attrNames[$slug] ?? Formatter::decode($k);
                    $options[$label] = $this->valueNames[$slug.'|'.$v] ?? Formatter::decode((string) $v);
                }
                $remoteProduct = $item->productId;
                $remoteVariation = $item->variationId;
                $items[$item->id] = [
                    'product_id' => $this->productMap[$remoteProduct] ?? null,
                    'product_variation_id' => $remoteVariation ? ($this->variationMap[$remoteVariation] ?? null) : null,
                    'name' => Formatter::decode($item->name),
                    'sku' => ($remoteVariation ? ($this->variationSkus[$remoteVariation] ?? null) : null) ?? ($this->productSkus[$remoteProduct] ?? null) ?? (($im['_sku'] ?? '') ?: null),
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
                $shippingItem ??= ['id' => $im['method_id'] ?? null, 'title' => Formatter::decode($item->name)];
            } elseif ($item->type === 'coupon') {
                $coupons[] = Formatter::decode($item->name);
                $couponLines[] = ['code' => Formatter::decode($item->name), 'discount' => round($item->total, 2), 'discount_tax' => round($item->tax, 2)];
            } elseif ($item->type === 'fee') {
                $fees[] = ['name' => Formatter::decode($item->name), 'total' => round($item->total, 2), 'tax' => round($item->tax, 2)];
            } elseif ($item->type === 'tax') {
                $rateId = (int) ($im['rate_id'] ?? 0);
                $taxLines[] = [
                    'tax_rate_id' => $rateId ? ($this->taxRateMap[$rateId] ?? null) : null,
                    'label' => Str::limit(Formatter::decode((string) (($im['label'] ?? '') !== '' ? $im['label'] : $item->name)), 120, '') ?: 'Tax',
                    'rate' => round((float) ($im['rate_percent'] ?? 0), 4),
                    'compound' => WordPressSource::yes($im['compound'] ?? '') || (string) ($im['compound'] ?? '') === '1',
                    'tax_total' => round((float) ($im['tax_amount'] ?? 0), 2),
                    'shipping_tax_total' => round((float) ($im['shipping_tax_amount'] ?? 0), 2),
                    'created_at' => $order->dateCreatedGmt ?? $now,
                    'updated_at' => $order->dateModifiedGmt ?? $now,
                ];
            }
        }

        $extra = ['wp_order_id' => $order->id];
        foreach ($this->metaKeys as $key) {
            if (isset($order->meta[$key]) && $order->meta[$key] !== '') {
                $extra[ltrim(str_replace('_wc_order_attribution_', 'attribution_', $key), '_')] = WordPressSource::unserialize($order->meta[$key]);
            }
        }
        if ($fees) {
            $extra['fees'] = $fees; // no fee columns in the schema
        }
        if ($couponLines && array_sum(array_column($couponLines, 'discount')) > 0) {
            $extra['coupon_lines'] = $couponLines;
        }
        $created = $order->dateCreatedGmt ?? $order->dateModifiedGmt ?? $now;
        $m = $order->meta;
        $b = $order->billing;
        $s = $order->shipping;

        $row = [
            'wp_id' => $order->id,
            'number' => $number,
            'order_key' => Str::limit($order->orderKey ?: 'wc_order_'.Str::random(13), 64, ''),
            'user_id' => $userId,
            'status' => $order->status,
            'currency' => $order->currency ?: $this->defaultCurrency,
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
            'billing_first_name' => self::v($b, 'first_name'),
            'billing_last_name' => self::v($b, 'last_name'),
            'billing_company' => self::v($b, 'company'),
            'billing_address_1' => self::v($b, 'address_1'),
            'billing_address_2' => self::v($b, 'address_2'),
            'billing_city' => self::v($b, 'city'),
            'billing_county' => self::v($b, 'state'),
            'billing_postcode' => self::v($b, 'postcode'),
            'billing_country' => self::country($b['country'] ?? null),
            'shipping_first_name' => self::v($s, 'first_name'),
            'shipping_last_name' => self::v($s, 'last_name'),
            'shipping_company' => self::v($s, 'company'),
            'shipping_address_1' => self::v($s, 'address_1'),
            'shipping_address_2' => self::v($s, 'address_2'),
            'shipping_city' => self::v($s, 'city'),
            'shipping_county' => self::v($s, 'state'),
            'shipping_postcode' => self::v($s, 'postcode'),
            'shipping_country' => self::country($s['country'] ?? null),
            'shipping_phone' => self::v($s, 'phone'),
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

        return ['row' => $row, 'items' => $items, 'tax_lines' => $taxLines, 'line_items' => $lineItems, 'fees' => (bool) $fees];
    }

    public static function v(array $a, string $key): ?string
    {
        $value = trim(Formatter::decode((string) ($a[$key] ?? '')));

        return $value === '' ? null : Str::limit($value, 250, '');
    }

    public static function country(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : substr($code, 0, 2);
    }
}
