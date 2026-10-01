<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Data\WcOrder;
use Pine\Commerce\Import\Data\WcRefund;

/** WooCommerce order storage (HPOS tables or legacy posts) – chosen by SiteProfile::$ordersStorage, not an adapter. */
interface OrderSource
{
    /** 'hpos' | 'posts' */
    public function storage(): string;

    public function count(): int;

    /** @return iterable<list<WcOrder>> chunks in ascending order id */
    public function orders(int $chunk = 500): iterable;

    /** @return list<WcRefund> refunds of the given parent orders, ascending id */
    public function refunds(array $wpOrderIds): array;

    public function refundCount(): int;
}
