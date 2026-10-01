<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Exports\CustomersCsvExport;
use Pine\Commerce\Exports\OrdersCsvExport;
use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Admin\CustomerQuery;
use Pine\Commerce\Services\Admin\LocalTime;
use Pine\Commerce\Services\Admin\OrderFilters;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads for the orders and customers lists. They take the list's own query string (filters, search,
 * status tab, ?ids=1,2,3 for "selected only"), so the file always matches what staff were looking at.
 */
class ExportController extends Controller
{
    use AdminIndex;

    /** ?format=orders (one row per order) | lines (default: one row per product line) */
    public function orders(Request $request): StreamedResponse
    {
        $filters = OrderFilters::fromRequest($request);
        $format = $request->query('format') === 'orders' ? 'orders' : 'lines';
        $query = $filters->apply(Order::query());

        $name = 'orders'.($filters->status !== 'all' ? '-'.$filters->status : '').'-'.LocalTime::now()->format('Y-m-d-His').'.csv';

        return OrdersCsvExport::download($query, $name, $format);
    }

    public function customers(Request $request): StreamedResponse
    {
        [$sort, $direction] = $this->sorting($request, CustomerQuery::SORTS, 'created_at', 'desc');

        return CustomersCsvExport::download(
            CustomerQuery::fromRequest($request)->sorted($sort, $direction),
            'customers-'.LocalTime::now()->format('Y-m-d-His').'.csv'
        );
    }
}
