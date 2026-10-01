<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Exports\CsvExport;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Admin\LocalTime;
use Pine\Commerce\Services\Admin\SalesReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics: sales for a UK date range compared with the previous period (or the same dates last year), sales over time
 * by day/week/month, best-selling products, sales by category and payment method, new vs returning customers.
 * Every table downloads as CSV with the same range. Figures come from Pine\Commerce\Services\Admin\SalesReport
 * (paid orders only, refunds subtracted, UK days).
 */
class ReportController extends Controller
{
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'month' => 'This month',
        'last_month' => 'Last month',
        'year' => 'This year',
        'last_year' => 'Last year',
        '12m' => 'Last 12 months',
        'custom' => 'Custom',
    ];

    public const COMPARE = ['previous' => 'Previous period', 'year' => 'Same period last year', 'none' => 'No comparison'];

    public const TABLES = ['sales' => 'Sales over time', 'products' => 'Top products', 'categories' => 'Sales by category', 'payments' => 'Sales by payment method', 'customers' => 'New vs returning customers', 'taxes' => 'Tax by rate'];

    public const MAX_DAYS = 3660;

    public function index(Request $request): View
    {
        [$report, $preset] = $this->range($request);
        $compareMode = is_string($request->query('compare')) && isset(self::COMPARE[$request->query('compare')]) ? $request->query('compare') : 'previous';
        $compare = match ($compareMode) {
            'year' => $report->previousYear(),
            'none' => null,
            default => $report->previous(),
        };
        $granularity = $this->granularity($request, $report);

        $totals = $report->totals();
        $compareTotals = $compare?->totals();
        $buckets = array_values($report->buckets($granularity));
        $compareBuckets = $compare ? array_values($compare->buckets($granularity)) : [];

        $delta = fn (string $key) => $compareTotals && $compareTotals[$key] > 0 ? SalesReport::trend((float) $totals[$key], (float) $compareTotals[$key]) : null;
        $stats = [];
        foreach (['net' => 'Net sales', 'orders' => 'Orders', 'aov' => 'Average order value', 'gross_sales' => 'Gross sales', 'discounts' => 'Discounts', 'refunds' => 'Refunds', 'shipping' => 'Shipping', 'items' => 'Items sold'] as $key => $label) {
            $stats[$key] = ['label' => $label, 'value' => $totals[$key], 'delta' => $delta($key), 'previous' => $compareTotals[$key] ?? null];
        }

        $top = $report->topProducts(20);
        $productIds = array_values(array_filter(array_column($top, 'product_id')));
        $products = $productIds
            ? Product::withTrashed()->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])->whereIn('id', $productIds)->get(['id', 'name', 'deleted_at'])->keyBy('id')
            : collect();

        $customers = $report->customerTypes();

        return view('commerce::admin.reports.index', [
            'report' => $report,
            'preset' => $preset,
            'presets' => self::PRESETS,
            'compare' => $compare,
            'compareMode' => $compareMode,
            'compareLabel' => $compare ? $this->rangeLabel($compare) : null,
            'rangeLabel' => $this->rangeLabel($report),
            'granularity' => $granularity,
            'totals' => $totals,
            'compareTotals' => $compareTotals,
            'stats' => $stats,
            'buckets' => $buckets,
            'chart' => [
                'labels' => array_column($buckets, 'label'),
                'net' => array_column($buckets, 'net'),
                'orders' => array_column($buckets, 'orders'),
                'aov' => array_column($buckets, 'aov'),
                'compareNet' => array_column($compareBuckets, 'net'),
                'compareOrders' => array_column($compareBuckets, 'orders'),
                'compareAov' => array_column($compareBuckets, 'aov'),
                'compareLabels' => array_column($compareBuckets, 'label'),
            ],
            'top' => $top,
            'products' => $products,
            'categories' => $report->byCategory(),
            'payments' => $report->byPaymentMethod(),
            'customers' => $customers,
            'newAccounts' => $report->newCustomers(),
            'query' => $this->query($request, $report, $preset, $compareMode, $granularity),
            'hasTax' => $totals['tax'] > 0,
            'taxRates' => $totals['tax'] != 0 ? $report->taxByRate() : [],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$report] = $this->range($request);
        $table = is_string($request->query('table')) && isset(self::TABLES[$request->query('table')]) ? $request->query('table') : 'sales';
        $granularity = $this->granularity($request, $report);
        $filename = 'sales-'.$table.'-'.$report->fromDate().'-to-'.$report->untilDate().'.csv';

        [$headings, $rows] = match ($table) {
            'products' => [
                ['Product', 'SKU', 'Product ID', 'Units sold', 'Orders', 'Net sales'],
                array_map(fn ($r) => [$r['name'], $r['sku'], $r['product_id'], $r['quantity'], $r['orders'], $r['revenue']], array_values($report->topProducts(1000))),
            ],
            'categories' => [
                ['Category', 'Path', 'Units sold', 'Orders', 'Net sales', 'Share %'],
                array_map(fn ($r) => [$r['name'], $r['path'] ? '/'.$r['path'].'/' : '', $r['quantity'], $r['orders'], $r['revenue'], $r['share']], array_values($report->byCategory())),
            ],
            'payments' => [
                ['Payment method', 'Code', 'Orders', 'Order totals', 'Refunds', 'Net sales', 'Share %'],
                array_map(fn ($r) => [$r['label'], $r['code'], $r['orders'], $r['gross'], $r['refunds'], $r['net'], $r['share']], $report->byPaymentMethod()),
            ],
            'taxes' => [
                ['Tax', 'Rate %', 'Orders', 'Tax on items', 'Tax on shipping', 'Total tax'],
                array_map(fn ($r) => [$r['label'], $r['rate'], $r['orders'], $r['tax'], $r['shipping_tax'], $r['total']], $report->taxByRate()),
            ],
            'customers' => (function () use ($report) {
                $types = $report->customerTypes();

                return [
                    ['Customer type', 'Customers', 'Orders', 'Net sales'],
                    [
                        ['New', $types['new']['customers'], $types['new']['orders'], $types['new']['net']],
                        ['Returning', $types['returning']['customers'], $types['returning']['orders'], $types['returning']['net']],
                    ],
                ];
            })(),
            default => [
                [ucfirst($granularity).' starting', 'Period', 'Orders', 'Gross sales', 'Discounts', 'Shipping', 'Tax', 'Refunds', 'Net sales', 'Average order value'],
                array_map(fn ($b) => [$b['start'], $b['label'], $b['orders'], $b['gross_sales'], $b['discounts'], $b['shipping'], $b['tax'], $b['refunds'], $b['net'], $b['aov']], array_values($report->buckets($granularity))),
            ],
        };

        return CsvExport::stream($filename, $headings, $rows);
    }

    /** @return array{0: SalesReport, 1: string} */
    protected function range(Request $request): array
    {
        $today = LocalTime::now()->startOfDay();
        $preset = is_string($request->query('range')) && isset(self::PRESETS[$request->query('range')]) ? $request->query('range') : null;
        $date = function (string $key) use ($request): ?Carbon {
            $value = $request->query($key);
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }
            try {
                return Carbon::createFromFormat('!Y-m-d', $value, LocalTime::TIMEZONE);
            } catch (\Throwable) {
                return null;
            }
        };
        $from = $date('from');
        $to = $date('to');
        if (($from || $to) && ($preset === null || $preset === 'custom')) {
            $preset = 'custom';
            $to ??= $today->copy();
            $from ??= $to->copy();
            if ($from->gt($to)) {
                [$from, $to] = [$to, $from];
            }
            if ($from->diffInDays($to) > self::MAX_DAYS) {
                $from = $to->copy()->subDays(self::MAX_DAYS);
            }
        } else {
            $preset ??= '30d';
            if ($preset === 'custom') {
                $preset = '30d';
            }
            [$from, $to] = match ($preset) {
                'today' => [$today->copy(), $today->copy()],
                'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
                '7d' => [$today->copy()->subDays(6), $today->copy()],
                '90d' => [$today->copy()->subDays(89), $today->copy()],
                'month' => [$today->copy()->startOfMonth(), $today->copy()],
                'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
                'year' => [$today->copy()->startOfYear(), $today->copy()],
                'last_year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()->startOfDay()],
                '12m' => [$today->copy()->subMonthsNoOverflow(12)->addDay(), $today->copy()],
                default => [$today->copy()->subDays(29), $today->copy()],
            };
        }

        return [new SalesReport($from->toDateString(), $to->toDateString()), $preset];
    }

    protected function granularity(Request $request, SalesReport $report): string
    {
        $requested = $request->query('by');
        if (is_string($requested) && isset(SalesReport::GRANULARITIES[$requested])) {
            // Daily buckets over years would be unreadable (and heavy): cap at ~2 years of days
            return $requested === 'day' && $report->days() > 731 ? 'week' : $requested;
        }

        return SalesReport::granularityFor($report->days());
    }

    protected function rangeLabel(SalesReport $report): string
    {
        $from = Carbon::parse($report->fromDate());
        $to = Carbon::parse($report->untilDate());
        if ($from->isSameDay($to)) {
            return $from->format('j M Y');
        }

        return $from->year === $to->year
            ? $from->format('j M').' – '.$to->format('j M Y')
            : $from->format('j M Y').' – '.$to->format('j M Y');
    }

    /** Current report parameters (for export links and the granularity switch). */
    protected function query(Request $request, SalesReport $report, string $preset, string $compare, string $granularity): array
    {
        return array_filter([
            'range' => $preset,
            'from' => $preset === 'custom' ? $report->fromDate() : null,
            'to' => $preset === 'custom' ? $report->untilDate() : null,
            'compare' => $compare !== 'previous' ? $compare : null,
            'by' => $granularity,
        ]);
    }
}
