<?php

namespace Pine\Commerce\Http\Requests\Admin\Catalogue;

use Pine\Commerce\Http\Requests\Admin\BulkActionRequest;

/** Bulk bar of Products › Reviews. */
class ReviewBulkRequest extends BulkActionRequest
{
    protected array $actions = ['approve', 'unapprove', 'delete'];
}
