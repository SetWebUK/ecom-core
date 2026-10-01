<?php

namespace Pine\Commerce\Scheduling\Tasks;

use Pine\Commerce\Models\Order;
use Pine\Commerce\Scheduling\Task;
use Pine\Commerce\Services\Checkout\OrderStock;

/**
 * Unpaid card/PayPal orders hold stock only for setting "checkout.hold_stock_minutes" (default 60, WooCommerce's
 * "Hold stock"; 0 = never cancel). Used by the scheduler and by the checkout-visit fallback
 * (CheckoutService::cancelStaleOrders()) while no cron runs.
 */
class CancelUnpaidOrders extends Task
{
    public static function minutes(): int
    {
        return (int) setting('checkout.hold_stock_minutes', 60);
    }

    public function skipReason(): ?string
    {
        return static::minutes() <= 0 ? 'Hold stock is 0 minutes: unpaid orders are never cancelled automatically.' : null;
    }

    public function handle(): string
    {
        return ($count = $this->cancel()).' unpaid '.str('order')->plural($count).' cancelled';
    }

    /** Cancel up to $limit stale unpaid orders (oldest first). @return int orders cancelled */
    public function cancel(int $limit = 50): int
    {
        $minutes = static::minutes();
        if ($minutes <= 0) {
            return 0;
        }
        $cancelled = 0;
        Order::where('status', 'pending')->where('created_via', 'checkout')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->where('meta', 'like', '%"stock_reduced"%')
            ->orderBy('id')->limit($limit)->get()
            ->each(function (Order $order) use (&$cancelled) {
                if (OrderStock::tracks($order) && ! $order->payments()->where('status', 'succeeded')->exists()) {
                    $order->updateStatus('cancelled', 'Unpaid order cancelled - time limit reached.');
                    $cancelled++;
                }
            });

        return $cancelled;
    }
}
