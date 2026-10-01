<?php

namespace Pine\Commerce\Import\Data;

/**
 * A WooCommerce order independent of its storage (HPOS or posts). Amounts are the raw WooCommerce values
 * (strings/floats), dates are GMT 'Y-m-d H:i:s' or null. `number` is null until an OrderNumberProvider sets it.
 *
 * totals: subtotal (sum of line subtotals, filled by the reader), discount, discountTax, shipping, shippingTax,
 *         tax (cart tax, excluding shipping tax), total
 * billing / shipping: first_name last_name company address_1 address_2 city state postcode country email phone
 */
final class WcOrder
{
    /** @param list<WcOrderItem> $items */
    public function __construct(
        public readonly int $id,
        public readonly string $status,
        public readonly string $currency,
        public readonly bool $pricesIncludeTax,
        public readonly array $totals,
        public readonly array $billing,
        public readonly array $shipping,
        public readonly string $paymentMethod,
        public readonly string $paymentMethodTitle,
        public readonly string $transactionId,
        public readonly int $customerId,
        public readonly string $customerNote,
        public readonly string $createdVia,
        public readonly string $orderKey,
        public readonly ?string $dateCreatedGmt,
        public readonly ?string $dateModifiedGmt,
        public readonly ?string $datePaidGmt,
        public readonly ?string $dateCompletedGmt,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly array $meta,
        public array $items = [],
        public ?string $number = null,
    ) {}

    public function billingEmail(): string
    {
        return strtolower(trim((string) ($this->billing['email'] ?? '')));
    }
}
