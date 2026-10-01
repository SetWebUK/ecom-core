<?php

namespace Pine\Commerce\Import\Data;

final class WcRefund
{
    /** @param list<WcOrderItem> $items */
    public function __construct(
        public readonly int $id,
        public readonly int $parentId,
        public readonly float $amount,
        public readonly string $reason,
        public readonly int $refundedBy,
        public readonly ?string $dateGmt,
        public readonly ?string $modifiedGmt,
        public array $items = [],
    ) {}
}
