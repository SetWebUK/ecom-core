<?php

namespace Pine\Commerce\Exports;

use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Admin\LocalTime;
use Pine\Commerce\Services\Admin\OrderStatus;
use Pine\Commerce\Services\Admin\PaymentMethods;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orders CSV, streamed in chunks (dates in UK time).
 *   lines (default)  one row per order line, order columns repeated – for stock / product analysis
 *   orders           one row per order – for bookkeeping
 */
class OrdersCsvExport extends CsvExport
{
    public const HEADINGS = [
        'Order number', 'Date', 'Status', 'Customer', 'Email', 'Phone', 'Billing company', 'Billing address', 'Billing postcode',
        'Shipping name', 'Shipping address', 'Shipping postcode', 'Shipping country', 'Payment method', 'Transaction ID',
        'Shipping method', 'Coupon', 'Item', 'SKU', 'Options', 'Qty', 'Unit price', 'Line total', 'Order subtotal',
        'Discount', 'Shipping', 'Tax', 'Order total', 'Refunded', 'Tracking',
    ];

    public const SUMMARY_HEADINGS = [
        'Order number', 'Date', 'Status', 'Customer', 'Email', 'Phone', 'Billing company', 'Billing address', 'Billing postcode',
        'Billing country', 'Shipping name', 'Shipping address', 'Shipping postcode', 'Shipping country', 'Items', 'Products',
        'Subtotal', 'Discount', 'Coupon', 'Shipping', 'Shipping method', 'Tax', 'Total', 'Refunded', 'Net total',
        'Payment method', 'Transaction ID', 'Paid', 'Completed', 'Tracking', 'Source', 'Created via',
    ];

    public static function download(Builder $query, ?string $filename = null, string $format = 'lines'): StreamedResponse
    {
        $filename ??= 'orders-'.LocalTime::now()->format('Y-m-d-His').'.csv';

        return $format === 'orders'
            ? static::stream($filename, self::SUMMARY_HEADINGS, static::summaryRows($query))
            : static::stream($filename, self::HEADINGS, static::rows($query));
    }

    public static function rows(Builder $query): \Generator
    {
        foreach ($query->with('items')->reorder()->lazyById(200, 'orders.id', 'id') as $order) {
            /** @var Order $order */
            $base = [
                $order->number,
                LocalTime::format($order->created_at, 'Y-m-d H:i'),
                OrderStatus::label($order->status),
                $order->billing_name,
                $order->email,
                $order->phone,
                $order->billing_company,
                implode(', ', array_filter([$order->billing_address_1, $order->billing_address_2, $order->billing_city, $order->billing_county])),
                $order->billing_postcode,
                $order->shipping_name,
                implode(', ', array_filter([$order->shipping_address_1, $order->shipping_address_2, $order->shipping_city, $order->shipping_county])),
                $order->shipping_postcode,
                $order->shipping_country,
                $order->payment_method_title ?: PaymentMethods::label($order->payment_method),
                $order->transaction_id,
                $order->shipping_method_title,
                $order->coupon_code,
            ];
            $totals = [
                $order->subtotal, $order->discount_total, $order->shipping_total, $order->tax_total, $order->total,
                $order->refunded_total, trim($order->tracking_carrier.' '.$order->tracking_number),
            ];
            $items = $order->items->isEmpty() ? [null] : $order->items;
            foreach ($items as $item) {
                yield [
                    ...$base,
                    $item?->name,
                    $item?->sku,
                    $item && $item->options ? collect($item->options)->map(fn ($v, $k) => "$k: ".(is_scalar($v) ? $v : json_encode($v)))->implode('; ') : null,
                    $item?->quantity,
                    $item?->unit_price,
                    $item?->total,
                    ...$totals,
                ];
            }
        }
    }

    public static function summaryRows(Builder $query): \Generator
    {
        foreach ($query->with('items:id,order_id,name,sku,quantity')->reorder()->lazyById(200, 'orders.id', 'id') as $order) {
            /** @var Order $order */
            yield [
                $order->number,
                LocalTime::format($order->created_at, 'Y-m-d H:i'),
                OrderStatus::label($order->status),
                $order->billing_name,
                $order->email,
                $order->phone,
                $order->billing_company,
                implode(', ', array_filter([$order->billing_address_1, $order->billing_address_2, $order->billing_city, $order->billing_county])),
                $order->billing_postcode,
                $order->billing_country,
                $order->shipping_name,
                implode(', ', array_filter([$order->shipping_address_1, $order->shipping_address_2, $order->shipping_city, $order->shipping_county])),
                $order->shipping_postcode,
                $order->shipping_country,
                $order->items->sum('quantity'),
                $order->items->map(fn ($i) => $i->quantity.' × '.$i->name.($i->sku ? ' ('.$i->sku.')' : ''))->implode('; '),
                $order->subtotal,
                $order->discount_total,
                $order->coupon_code,
                $order->shipping_total,
                $order->shipping_method_title,
                $order->tax_total,
                $order->total,
                $order->refunded_total,
                number_format((float) $order->total - (float) $order->refunded_total, 2, '.', ''),
                $order->payment_method_title ?: PaymentMethods::label($order->payment_method),
                $order->transaction_id,
                LocalTime::format($order->paid_at, 'Y-m-d H:i'),
                LocalTime::format($order->completed_at, 'Y-m-d H:i'),
                trim($order->tracking_carrier.' '.$order->tracking_number),
                $order->source,
                $order->created_via,
            ];
        }
    }
}
