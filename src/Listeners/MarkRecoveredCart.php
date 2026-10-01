<?php

namespace Pine\Commerce\Listeners;

use Pine\Commerce\Events\OrderPlaced;
use Pine\Commerce\Services\Recovery\AbandonedCartRecovery;

/** OrderPlaced: an order from a basket that was sent an abandoned-cart reminder counts as recovered. */
class MarkRecoveredCart
{
    public function handle(OrderPlaced $event): void
    {
        app(AbandonedCartRecovery::class)->markRecovered($event->order);
    }
}
