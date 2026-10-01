<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Mail\Support\OrderEmail;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\OrderNote;

/**
 * Customer: staff added a note to the order for the customer (WC "Customer note").
 * The back office sends it: new CustomerNote($order, $noteModelOrText).
 */
class CustomerNote extends OrderEmail
{
    public string $noteText;

    public function __construct(Order $order, OrderNote|string $note)
    {
        parent::__construct($order);
        $this->noteText = $note instanceof OrderNote ? (string) $note->note : $note;
    }

    public function label(): string
    {
        return 'Customer note';
    }

    protected function subjectText(): string
    {
        return sprintf('Note added to your %s order from %s', static::storeName(), \Pine\Commerce\Services\Checkout\UkTime::format($this->order->created_at ?? now()));
    }

    protected function headingText(): string
    {
        return 'A note has been added to your order';
    }

    protected function template(): string
    {
        return 'customer-note';
    }

    protected function extra(): array
    {
        return ['note' => $this->noteText];
    }
}
