<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Mail\Support\OrderEmail;

/** Store owner: a processing / on-hold order was cancelled (WC "Cancelled order"). */
class AdminCancelledOrder extends OrderEmail
{
    public function label(): string
    {
        return 'Cancelled order';
    }

    protected function subjectText(): string
    {
        return sprintf('[%s]: Order #%s has been cancelled', static::storeName(), $this->order->number);
    }

    protected function headingText(): string
    {
        return 'Order Cancelled: #'.$this->order->number;
    }

    protected function template(): string
    {
        return 'admin-cancelled-order';
    }

    protected function replyToCustomer(): bool
    {
        return true;
    }
}
