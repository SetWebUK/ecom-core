<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Mail\Support\OrderEmail;

/** Store owner: a new order was paid / placed on hold (WC "New order"). */
class AdminNewOrder extends OrderEmail
{
    public function label(): string
    {
        return 'New order';
    }

    protected function subjectText(): string
    {
        return sprintf('[%s]: New order #%s', static::storeName(), $this->order->number);
    }

    protected function headingText(): string
    {
        return 'New Order: #'.$this->order->number;
    }

    protected function template(): string
    {
        return 'admin-new-order';
    }

    protected function replyToCustomer(): bool
    {
        return true;
    }
}
