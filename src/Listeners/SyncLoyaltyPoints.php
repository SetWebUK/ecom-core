<?php

namespace Pine\Commerce\Listeners;

use Pine\Commerce\Events\OrderStatusChanged;
use Pine\Commerce\Services\Loyalty\Loyalty;

/**
 * Loyalty points follow the order (feature "loyalty"): paid (processing / completed) earns them once; cancelled,
 * failed or refunded takes back what was earned and gives back what was spent.
 */
class SyncLoyaltyPoints
{
    public function handle(OrderStatusChanged $event): void
    {
        if (! Loyalty::enabled() || ! $event->order->user_id) {
            return;
        }
        if (in_array($event->to, ['processing', 'completed'], true)) {
            Loyalty::earnFor($event->order);
        } elseif (in_array($event->to, ['cancelled', 'failed', 'refunded'], true)) {
            Loyalty::reverseFor($event->order);
        }
    }
}
