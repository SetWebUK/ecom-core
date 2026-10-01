<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Mail\Support\OrderEmail;

/** Customer: order received and paid, now being processed (WC "Processing order"). */
class CustomerProcessingOrder extends OrderEmail
{
    public function label(): string
    {
        return 'Processing order';
    }

    public function invoiceEmailKey(): ?string
    {
        return 'customer_processing';
    }

    protected function subjectText(): string
    {
        return sprintf('Your %s order has been received!', static::storeName());
    }

    protected function headingText(): string
    {
        return 'Thank you for your order';
    }

    protected function template(): string
    {
        return 'customer-processing-order';
    }
}
