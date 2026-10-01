<?php

namespace Pine\Commerce\Contracts;

use Illuminate\Support\Collection;
use Pine\Commerce\Models\ShippingMethod;

/**
 * Prices a delivery option (a ShippingMethod row from Admin › Settings › Shipping) for the current basket instead of
 * its flat cost. Registered for method codes with Commerce::shippingCalculator('pattern', Calculator::class), where the
 * pattern is matched against shipping_methods.code with fnmatch() ('dpd_*', 'weight', '*').
 *
 * $context: subtotal (float, before discounts), discount (float), country (string), free_shipping (bool, a coupon
 * grants free shipping), lines (Collection<CartLine>, also passed as $lines).
 */
interface ShippingCalculator
{
    /**
     * Cost of $method for this basket (ex tax), or null when the method must not be offered for it.
     * $cost is the method's own flat cost from the admin.
     *
     * @param  Collection<int, \Pine\Commerce\Services\Checkout\CartLine>  $lines
     */
    public function cost(ShippingMethod $method, float $cost, Collection $lines, array $context): ?float;
}
