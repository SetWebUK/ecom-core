<?php

namespace Pine\Commerce\Services\Payments;

use RuntimeException;

/** A payment provider call failed; the message is safe to show to staff/customers. */
class PaymentException extends RuntimeException
{
}
