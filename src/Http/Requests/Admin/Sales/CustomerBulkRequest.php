<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

use Pine\Commerce\Http\Requests\Admin\BulkActionRequest;

/** Customers list bulk bar: marketing consent on/off. */
class CustomerBulkRequest extends BulkActionRequest
{
    protected array $actions = ['subscribe', 'unsubscribe'];
}
