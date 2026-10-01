<?php

namespace Pine\Commerce\Services\Checkout;

use RuntimeException;

/**
 * A basket/checkout problem the customer can act on (out of stock, invalid coupon, bad quantity...).
 * The message is shown to the customer as-is (plain text, WooCommerce wording).
 */
class CartException extends RuntimeException
{
}
