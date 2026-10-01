<?php

namespace Pine\Commerce\Import\Data;

/** A woocommerce_order_items row + its meta (first value per key). type: line_item|shipping|fee|coupon|tax */
final class WcOrderItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $name,
        public readonly int $productId = 0,
        public readonly int $variationId = 0,
        public readonly int $qty = 0,
        public readonly float $subtotal = 0.0,
        public readonly float $total = 0.0,
        public readonly float $tax = 0.0,
        public readonly array $meta = [],
    ) {}
}
