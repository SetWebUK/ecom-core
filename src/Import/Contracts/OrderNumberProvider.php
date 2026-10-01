<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Data\WcOrder;

/** Sequential/custom order number plugins. The first non-null number wins; the core fallback is the order id. */
interface OrderNumberProvider
{
    public function orderNumber(WcOrder $order): ?string;

    /** Highest number the plugin has issued (its counter), used for settings `orders.starting_number`. */
    public function lastIssuedNumber(): ?int;
}
