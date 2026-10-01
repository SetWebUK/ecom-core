<?php

namespace Pine\Commerce\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Pine\Commerce\Models\Order;

/** A client replacement for an order email (ExtensionApiTest). */
class FixtureOrderMail extends Mailable
{
    public function __construct(public Order $order) {}

    public function build(): static
    {
        return $this->subject('Your order '.$this->order->number)->html('<p>Custom completed email</p>');
    }
}
