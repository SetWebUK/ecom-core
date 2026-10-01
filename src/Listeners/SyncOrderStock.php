<?php

namespace Pine\Commerce\Listeners;

use Pine\Commerce\Events\OrderStatusChanged;
use Pine\Commerce\Services\Checkout\OrderStock;

/**
 * Keeps stock, coupon usage and total_sales in step with the status of storefront orders
 * (wc_maybe_reduce_stock_levels / wc_maybe_increase_stock_levels):
 *   processing / completed / on-hold  -> stock taken + usage recorded (no-op when already done at checkout)
 *   cancelled / failed                -> stock put back + usage reversed (unpaid orders must not hold stock)
 * Only orders created by the checkout are tracked (see OrderStock::tracks()).
 */
class SyncOrderStock
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        if (! OrderStock::tracks($order)) {
            return;
        }

        if (in_array($event->to, ['processing', 'completed', 'on-hold'], true)) {
            OrderStock::reduce($order);
            OrderStock::recordUsage($order);
        } elseif (in_array($event->to, ['cancelled', 'failed'], true)) {
            OrderStock::restore($order);
            OrderStock::reverseUsage($order);
        }
    }
}
