<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

use Pine\Commerce\Http\Requests\Admin\BulkActionRequest;

/** Orders list bulk bar: change the status of the selected orders. */
class OrderBulkRequest extends BulkActionRequest
{
    protected array $actions = ['processing', 'completed', 'on-hold'];

    protected int $max = 100;
}
