<?php

namespace Pine\Commerce\Services\Checkout;

use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Illuminate\Support\Facades\DB;

/**
 * Stock, coupon usage and total_sales bookkeeping for checkout orders, tracked with flags in orders.meta
 * so every step happens exactly once:
 *   meta.stock_reduced   stock taken for the order's lines
 *   meta.usage_recorded  coupon usage_count + product total_sales incremented
 * Only orders created by the storefront checkout carry these flags; imported and back-office orders are
 * never touched.
 */
class OrderStock
{
    public static function tracks(Order $order): bool
    {
        return array_key_exists('stock_reduced', (array) $order->meta);
    }

    /** Take stock for every line (locks the product/variation rows). */
    public static function reduce(Order $order): void
    {
        if (! static::tracks($order) || ($order->meta['stock_reduced'] ?? false)) {
            return;
        }
        $changes = static::adjust($order, -1);
        static::setMeta($order, ['stock_reduced' => true]);
        if ($changes) {
            $order->addNote('Stock levels reduced: '.implode(', ', $changes));
        }
    }

    /** Put the order's stock back (cancelled orders, or re-opening an unpaid order). */
    public static function restore(Order $order, bool $withNote = true): void
    {
        if (! static::tracks($order) || ! ($order->meta['stock_reduced'] ?? false)) {
            return;
        }
        $changes = static::adjust($order, 1);
        static::setMeta($order, ['stock_reduced' => false]);
        if ($changes && $withNote) {
            $order->addNote('Stock levels increased: '.implode(', ', $changes));
        }
    }

    public static function recordUsage(Order $order): void
    {
        if (! static::tracks($order) || ($order->meta['usage_recorded'] ?? false)) {
            return;
        }
        static::usage($order, 1);
        static::setMeta($order, ['usage_recorded' => true]);
    }

    public static function reverseUsage(Order $order): void
    {
        if (! static::tracks($order) || ! ($order->meta['usage_recorded'] ?? false)) {
            return;
        }
        static::usage($order, -1);
        static::setMeta($order, ['usage_recorded' => false]);
    }

    /** @return array<int, string> "Name (SKU) 3→2" descriptions */
    protected static function adjust(Order $order, int $direction): array
    {
        $changes = [];
        DB::transaction(function () use ($order, $direction, &$changes) {
            $items = $order->items()->get();
            $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id')->filter())->lockForUpdate()->get()->keyBy('id');
            $variations = ProductVariation::whereIn('id', $items->pluck('product_variation_id')->filter())->lockForUpdate()->get()->keyBy('id');

            foreach ($items as $item) {
                $qty = max(0, (int) $item->quantity - (int) $item->refunded_quantity) * $direction;
                if ($qty === 0) {
                    continue;
                }
                $variation = $item->product_variation_id ? $variations->get($item->product_variation_id) : null;
                $holder = $variation && $variation->manage_stock ? $variation : $products->get($item->product_id);
                if (! $holder || ! $holder->manage_stock) {
                    continue;
                }
                $before = (int) $holder->stock_quantity;
                $holder->stock_quantity = $before + $qty;
                $holder->save();
                $changes[] = $item->name.($item->sku ? ' ('.$item->sku.')' : '').' '.$before.'→'.$holder->stock_quantity;
            }
        });

        return $changes;
    }

    protected static function usage(Order $order, int $direction): void
    {
        DB::transaction(function () use ($order, $direction) {
            $codes = array_filter(array_map('trim', explode(',', (string) $order->coupon_code)));
            if ($codes) {
                $coupons = Coupon::whereIn(DB::raw('LOWER(code)'), array_map('mb_strtolower', $codes))->lockForUpdate()->get();
                foreach ($coupons as $coupon) {
                    $coupon->usage_count = max(0, (int) $coupon->usage_count + $direction);
                    $coupon->save();
                }
            }
            foreach ($order->items()->get() as $item) {
                if ($item->product_id) {
                    $direction > 0
                        ? Product::withTrashed()->whereKey($item->product_id)->increment('total_sales', (int) $item->quantity)
                        : Product::withTrashed()->whereKey($item->product_id)->where('total_sales', '>=', (int) $item->quantity)->decrement('total_sales', (int) $item->quantity);
                }
            }
        });
    }

    protected static function setMeta(Order $order, array $values): void
    {
        $order->meta = array_merge((array) $order->meta, $values);
        $order->saveQuietly();
    }
}
