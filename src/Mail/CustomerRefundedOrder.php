<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Mail\Support\OrderEmail;
use Pine\Commerce\Models\Order;

/** Customer: the order was (partially) refunded (WC "Refunded order"). */
class CustomerRefundedOrder extends OrderEmail
{
    public function __construct(Order $order, public ?float $amount = null)
    {
        parent::__construct($order);
    }

    public function label(): string
    {
        return $this->isPartial() ? 'Partially refunded order' : 'Refunded order';
    }

    public function isPartial(): bool
    {
        return (float) $this->order->refunded_total + 0.001 < (float) $this->order->total;
    }

    protected function subjectText(): string
    {
        return $this->isPartial()
            ? sprintf('Your %s order #%s has been partially refunded', static::storeName(), $this->order->number)
            : sprintf('Your %s order #%s has been refunded', static::storeName(), $this->order->number);
    }

    protected function headingText(): string
    {
        return $this->isPartial() ? 'Partial Refund: Order '.$this->order->number : 'Order Refunded: '.$this->order->number;
    }

    protected function template(): string
    {
        return 'customer-refunded-order';
    }

    protected function extra(): array
    {
        return ['partial' => $this->isPartial(), 'amount' => $this->amount];
    }
}
