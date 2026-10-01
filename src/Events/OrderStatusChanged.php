<?php

namespace Pine\Commerce\Events;

use Pine\Commerce\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order, public ?string $from, public string $to)
    {
    }
}
