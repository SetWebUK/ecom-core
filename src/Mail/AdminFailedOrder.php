<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Mail\Support\OrderEmail;

/** Store owner: payment for a pending / on-hold order failed (WC "Failed order"). */
class AdminFailedOrder extends OrderEmail
{
    public function label(): string
    {
        return 'Failed order';
    }

    protected function subjectText(): string
    {
        return sprintf('[%s]: Order #%s has failed', static::storeName(), $this->order->number);
    }

    protected function headingText(): string
    {
        return 'Order Failed: #'.$this->order->number;
    }

    protected function template(): string
    {
        return 'admin-failed-order';
    }

    protected function replyToCustomer(): bool
    {
        return true;
    }
}
