<?php

namespace Pine\Commerce\Http\Requests\Admin\Catalogue;

use Pine\Commerce\Http\Requests\Admin\BulkActionRequest;

/** Bulk bar of Products › Stock alerts (ids are product ids). */
class StockAlertBulkRequest extends BulkActionRequest
{
    protected array $actions = ['notify', 'delete'];
}
