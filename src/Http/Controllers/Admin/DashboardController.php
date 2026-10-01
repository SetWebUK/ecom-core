<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\FormSubmission;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Admin\LocalTime;
use Pine\Commerce\Services\Admin\SalesReport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Pine\Commerce\Contracts\DashboardWidget;
use Pine\Commerce\Extensions\ExtensionRegistry;
use Pine\Commerce\Support\Features;
use Pine\Commerce\Services\Recovery\AbandonedCartRecovery;
use Throwable;

/**
 * Back-office home: sales for a date range (UK time) compared with the previous period, orders waiting to be
 * fulfilled, stock warnings, best sellers and the latest contact-form messages.
 */
class DashboardController extends Controller
{
    public const RANGES = [
        'today' => ['label' => 'Today', 'days' => 1, 'compare' => 'yesterday'],
        '7d' => ['label' => '7 days', 'days' => 7, 'compare' => 'previous 7 days'],
        '30d' => ['label' => '30 days', 'days' => 30, 'compare' => 'previous 30 days'],
        '90d' => ['label' => '90 days', 'days' => 90, 'compare' => 'previous 90 days'],
    ];

    public function index(Request $request): View
    {
        $range = is_string($request->query('range')) && isset(self::RANGES[$request->query('range')]) ? $request->query('range') : '30d';
        $config = self::RANGES[$range];

        $report = SalesReport::lastDays($config['days']);
        $previous = $report->previous();
        $totals = $report->totals();
        $previousTotals = $previous->totals();
        $newCustomers = $report->newCustomers();
        $previousCustomers = $previous->newCustomers();

        $current = $range === 'today' ? $report->hourlySeries() : $report->series();
        $before = array_values($range === 'today' ? $previous->hourlySeries() : $previous->series());
        $chart = [
            'labels' => array_values(array_column($current, 'label')),
            'revenue' => array_values(array_column($current, 'revenue')),
            'orders' => array_values(array_column($current, 'orders')),
            'previousRevenue' => array_column($before, 'revenue'),
            'previousOrders' => array_column($before, 'orders'),
            'previousLabels' => array_column($before, 'label'),
        ];

        // A % change against nothing is meaningless ("+100%"), so show the plain comparison instead.
        $stat = fn (float $now, float $before, string $none): array => [
            'value' => $now,
            'delta' => $before > 0 ? SalesReport::trend($now, $before) : null,
            'hint' => $before > 0 ? 'vs '.$config['compare'] : $none.' '.($range === 'today' ? 'yesterday' : 'in the '.$config['compare']),
        ];
        $stats = [
            'revenue' => $stat($totals['net'], $previousTotals['net'], 'No sales'),
            'orders' => $stat($totals['orders'], $previousTotals['orders'], 'No orders'),
            'aov' => $stat($totals['aov'], $previousTotals['aov'], 'No orders'),
            'customers' => $stat($newCustomers, $previousCustomers, 'None'),
        ];

        // Orders to fulfil: oldest first so nothing waits too long
        $toFulfil = Order::query()
            ->where('status', 'processing')
            ->withCount('items')
            ->oldest()
            ->limit(6)
            ->get(['id', 'number', 'email', 'billing_first_name', 'billing_last_name', 'total', 'shipping_method_title', 'created_at']);
        $toFulfilCount = $toFulfil->count() < 6 ? $toFulfil->count() : Order::where('status', 'processing')->count();

        // Stock
        $threshold = max(0, (int) setting('inventory.low_stock_threshold', 2));
        $lowStockQuery = Product::query()
            ->published()
            ->where('manage_stock', true)
            ->where('stock_quantity', '>', 0)
            ->whereRaw('stock_quantity <= COALESCE(low_stock_threshold, ?)', [$threshold]);
        $lowStock = (clone $lowStockQuery)
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->orderBy('stock_quantity')->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'sku', 'stock_quantity']);
        $lowStockCount = $lowStock->count() < 5 ? $lowStock->count() : $lowStockQuery->count();
        $outOfStockCount = Product::query()->published()->where('stock_status', 'outofstock')->count();

        // Best sellers in the range
        $top = $report->topProducts(5);
        $topIds = array_filter(array_column($top, 'product_id'));
        $topProducts = $topIds
            ? Product::withTrashed()->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])->whereIn('id', $topIds)->get(['id', 'name', 'sku', 'deleted_at'])->keyBy('id')
            : collect();

        // Inbox
        $submissions = FormSubmission::query()->latest()->limit(5)->get(['id', 'form', 'name', 'email', 'subject', 'message', 'read_at', 'created_at']);
        $unreadCount = FormSubmission::whereNull('read_at')->count();

        $lastOrder = Order::query()->whereIn('status', Order::PAID_STATUSES)->latest()->first(['id', 'number', 'total', 'created_at']);
        $hour = (int) LocalTime::now()->format('G');

        return view('commerce::admin.dashboard.index', [
            'range' => $range,
            'ranges' => self::RANGES,
            'config' => $config,
            'report' => $report,
            'totals' => $totals,
            'stats' => $stats,
            'chart' => $chart,
            'hasSales' => $totals['orders'] > 0 || array_sum($chart['previousOrders']) > 0,
            'toFulfil' => $toFulfil,
            'toFulfilCount' => $toFulfilCount,
            'lowStock' => $lowStock,
            'lowStockCount' => $lowStockCount,
            'outOfStockCount' => $outOfStockCount,
            'threshold' => $threshold,
            'top' => $top,
            'topProducts' => $topProducts,
            'submissions' => $submissions,
            'unreadCount' => $unreadCount,
            'lastOrder' => $lastOrder,
            'greeting' => $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'),
            'showMessages' => Features::enabled('contact_form', false),
            'widgets' => static::widgets($request),
            'recovery' => static::recovery($config['days']),
        ]);
    }

    /** Abandoned-cart reminders card: only while reminders are on or have ever won an order back. */
    protected static function recovery(int $days): ?array
    {
        try {
            if (! Features::enabled('abandoned_carts', false)) {
                return null;
            }
            $on = AbandonedCartRecovery::enabled();
            if (! $on && ! \Illuminate\Support\Facades\DB::table('cart_recovery_emails')->exists()) {
                return null;
            }

            return AbandonedCartRecovery::stats(now()->subDays($days)) + ['on' => $on];
        } catch (Throwable) {
            return null; // migrations not run yet
        }
    }

    /**
     * Client cards (Commerce::dashboardWidget()) visible to this user, by 'sort': [key => [title, subtitle, view, data,
     * wide]]. A widget that throws is reported and left out, so one bad card never breaks the dashboard.
     *
     * @return array<string, array{title:?string, subtitle:?string, view:string, data:array, wide:bool}>
     */
    public static function widgets(Request $request): array
    {
        $entries = app(ExtensionRegistry::class)->dashboardWidgets();
        uasort($entries, fn ($a, $b) => ($a['sort'] ?? 100) <=> ($b['sort'] ?? 100));
        $isAdmin = (bool) $request->user()?->isAdmin();
        $out = [];
        foreach ($entries as $key => $entry) {
            if ((! empty($entry['feature']) && ! Features::enabled((string) $entry['feature'], false)) || (! empty($entry['admin']) && ! $isAdmin)) {
                continue;
            }
            try {
                if (isset($entry['class'])) {
                    $widget = is_string($entry['class']) ? app($entry['class']) : $entry['class'];
                    if (! $widget instanceof DashboardWidget || ! $widget->visible($request)) {
                        continue;
                    }
                    $out[$key] = ['title' => $widget->title(), 'subtitle' => null, 'view' => $widget->view(), 'data' => $widget->data($request), 'wide' => (bool) $entry['wide']];

                    continue;
                }
                $data = $entry['data'] ?? null;
                $out[$key] = [
                    'title' => $entry['title'] ?? null,
                    'subtitle' => $entry['subtitle'] ?? null,
                    'view' => (string) $entry['view'],
                    'data' => is_callable($data) ? (array) $data($request) : (array) ($data ?? []),
                    'wide' => (bool) ($entry['wide'] ?? false),
                ];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $out;
    }

    /** Living style guide: every <x-admin.*> component with real data (not in the menu – see docs/ADMIN_UI.md). */
    public function uiKit(): View
    {
        return view('commerce::admin.dashboard.ui-kit', [
            'products' => Product::query()->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])->published()->latest('id')->limit(3)->get(['id', 'name', 'sku', 'price', 'stock_status', 'status']),
            'orders' => Order::query()->latest('id')->limit(3)->get(['id', 'number', 'status', 'total', 'email', 'billing_first_name', 'billing_last_name', 'created_at']),
        ]);
    }
}
