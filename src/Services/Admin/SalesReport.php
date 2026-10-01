<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pine\Commerce\Support\Sql;

/**
 * Sales figures for the dashboard and the Analytics page.
 *
 * Rules (the same everywhere, so Home and Analytics always agree):
 *  - Only orders in Order::PAID_STATUSES (processing, completed, on hold) count as sales; soft-deleted orders never do.
 *  - Money is attributed to the day the order was placed (not the day it was refunded).
 *  - "Net sales" = what customers paid (after discounts, incl. shipping and tax) minus refunds = SUM(total - refunded_total).
 *    "Gross sales" = product sales before discounts = SUM(subtotal). So: gross − discounts + shipping + tax − refunds = net.
 *  - Days are UK days: the range is given as UK local dates and converted to UTC for querying; orders are grouped by UTC
 *    hour in SQL and assigned to UK buckets in PHP, so days either side of a clock change (BST/GMT) are right.
 */
class SalesReport
{
    /** Tables whose "table.column" references in raw SQL get the connection prefix (Support\Sql::qualify). */
    protected const TABLES = ['order_items', 'orders', 'categories', 'products'];

    public Carbon $from;   // UTC

    public Carbon $until;  // UTC

    public const GRANULARITIES = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'];

    public function __construct(Carbon|string|null $from, Carbon|string|null $until)
    {
        $tz = LocalTime::TIMEZONE;
        $this->from = Carbon::parse($from ?? now()->subDays(29), $tz)->setTimezone($tz)->startOfDay()->utc();
        $this->until = Carbon::parse($until ?? now(), $tz)->setTimezone($tz)->endOfDay()->utc();
    }

    public static function lastDays(int $days): self
    {
        $today = LocalTime::now();

        return new self($today->copy()->subDays($days - 1)->toDateString(), $today->toDateString());
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->until) + 1;
    }

    /** First and last UK date of the range (Y-m-d). */
    public function fromDate(): string
    {
        return $this->from->copy()->setTimezone(LocalTime::TIMEZONE)->toDateString();
    }

    public function untilDate(): string
    {
        return $this->until->copy()->setTimezone(LocalTime::TIMEZONE)->toDateString();
    }

    /** The same-length period immediately before this one. */
    public function previous(): self
    {
        $days = $this->days();
        $tz = LocalTime::TIMEZONE;
        $end = $this->from->copy()->setTimezone($tz)->subDay();

        return new self($end->copy()->subDays($days - 1)->toDateString(), $end->toDateString());
    }

    /** The same dates one year earlier (29 Feb falls back to 28 Feb). */
    public function previousYear(): self
    {
        $tz = LocalTime::TIMEZONE;

        return new self(
            $this->from->copy()->setTimezone($tz)->subYearNoOverflow()->toDateString(),
            $this->until->copy()->setTimezone($tz)->subYearNoOverflow()->toDateString(),
        );
    }

    /** Sensible chart bucket size for a range length. */
    public static function granularityFor(int $days): string
    {
        return match (true) {
            $days <= 62 => 'day',
            $days <= 190 => 'week',
            default => 'month',
        };
    }

    protected function paidOrders()
    {
        return Order::query()->whereIn('status', Order::PAID_STATUSES)->whereBetween('created_at', [$this->from, $this->until]);
    }

    /**
     * Tax collected per rate (order_tax_lines of paid orders); orders without per-rate lines (placed before v1.1 or
     * imported) are grouped under the store's tax label.
     *
     * @return list<array{label:string, rate:?float, orders:int, tax:float, shipping_tax:float, total:float}>
     */
    public function taxByRate(): array
    {
        $rows = [];
        try {
            $lines = DB::table('order_tax_lines')
                ->join('orders', 'orders.id', '=', 'order_tax_lines.order_id')
                ->whereNull('orders.deleted_at')
                ->whereIn('orders.status', Order::PAID_STATUSES)
                ->whereBetween('orders.created_at', [$this->from, $this->until])
                ->groupBy('order_tax_lines.label', 'order_tax_lines.rate')
                ->orderByDesc(DB::raw('SUM(order_tax_lines.tax_total + order_tax_lines.shipping_tax_total)'))
                ->selectRaw('order_tax_lines.label as label, order_tax_lines.rate as rate, COUNT(DISTINCT order_tax_lines.order_id) as orders, SUM(order_tax_lines.tax_total) as tax, SUM(order_tax_lines.shipping_tax_total) as shipping_tax')
                ->get();
            foreach ($lines as $line) {
                $rows[] = ['label' => (string) $line->label, 'rate' => (float) $line->rate, 'orders' => (int) $line->orders,
                    'tax' => round((float) $line->tax, 2), 'shipping_tax' => round((float) $line->shipping_tax, 2),
                    'total' => round((float) $line->tax + (float) $line->shipping_tax, 2)];
            }
            $legacy = $this->paidOrders()->where('tax_total', '!=', 0)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_tax_lines')->whereColumn('order_tax_lines.order_id', 'orders.id'))
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM(tax_total),0) as tax')->toBase()->first();
        } catch (\Throwable) {
            $legacy = $this->paidOrders()->where('tax_total', '!=', 0)->selectRaw('COUNT(*) as orders, COALESCE(SUM(tax_total),0) as tax')->toBase()->first();
        }
        if ($legacy && (int) $legacy->orders > 0) {
            $rows[] = ['label' => (string) setting('tax.label', 'VAT').' (no rate breakdown)', 'rate' => null, 'orders' => (int) $legacy->orders,
                'tax' => round((float) $legacy->tax, 2), 'shipping_tax' => 0.0, 'total' => round((float) $legacy->tax, 2)];
        }

        return $rows;
    }

    /** @return array{orders:int, gross:float, refunds:float, net:float, aov:float, discounts:float, shipping:float, tax:float, gross_sales:float, items:int} */
    public function totals(): array
    {
        $row = $this->paidOrders()
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total),0) as gross, COALESCE(SUM(refunded_total),0) as refunds, COALESCE(SUM(discount_total),0) as discounts, COALESCE(SUM(shipping_total),0) as shipping, COALESCE(SUM(tax_total),0) as tax, COALESCE(SUM(subtotal),0) as subtotal')
            ->toBase()
            ->first();
        $items = (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->whereBetween('orders.created_at', [$this->from, $this->until])
            ->sum(DB::raw(Sql::qualify('order_items.quantity - order_items.refunded_quantity', self::TABLES)));
        $orders = (int) $row->orders;
        $net = round((float) $row->gross - (float) $row->refunds, 2);

        return [
            'orders' => $orders,
            'gross' => round((float) $row->gross, 2),          // order totals before refunds (kept for the dashboard)
            'gross_sales' => round((float) $row->subtotal, 2), // product sales before discounts
            'refunds' => round((float) $row->refunds, 2),
            'net' => $net,
            'aov' => $orders ? round($net / $orders, 2) : 0.0,
            'discounts' => round((float) $row->discounts, 2),
            'shipping' => round((float) $row->shipping, 2),
            'tax' => round((float) $row->tax, 2),
            'items' => $items,
        ];
    }

    /**
     * Revenue and order count per day (or per month when $byMonth), every bucket present. (Dashboard chart.)
     *
     * @return array<string, array{label:string, revenue:float, orders:int}>
     */
    public function series(bool $byMonth = false): array
    {
        return array_map(
            fn (array $b) => ['label' => $b['label'], 'revenue' => $b['net'], 'orders' => $b['orders']],
            $this->buckets($byMonth ? 'month' : 'day')
        );
    }

    /**
     * Full figures per UK day / week (Monday start) / month, every bucket present (zeros included).
     *
     * @return array<string, array{key:string, label:string, start:string, orders:int, gross_sales:float, discounts:float, shipping:float, tax:float, refunds:float, net:float, aov:float}>
     */
    public function buckets(string $granularity = 'day'): array
    {
        $granularity = array_key_exists($granularity, self::GRANULARITIES) ? $granularity : 'day';
        $tz = LocalTime::TIMEZONE;
        $start = $this->from->copy()->setTimezone($tz);
        $end = $this->until->copy()->setTimezone($tz);
        $multiYear = $start->year !== $end->year;

        $empty = fn (string $key, string $label, string $startDate) => [
            'key' => $key, 'label' => $label, 'start' => $startDate, 'orders' => 0, 'gross_sales' => 0.0, 'discounts' => 0.0,
            'shipping' => 0.0, 'tax' => 0.0, 'refunds' => 0.0, 'net' => 0.0, 'aov' => 0.0,
        ];

        $buckets = [];
        if ($granularity === 'month') {
            foreach (CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $end->copy()->startOfMonth()) as $month) {
                $buckets[$month->format('Y-m')] = $empty($month->format('Y-m'), $month->format('M Y'), $month->toDateString());
            }
        } elseif ($granularity === 'week') {
            foreach (CarbonPeriod::create($start->copy()->startOfWeek(Carbon::MONDAY), '1 week', $end->copy()->startOfWeek(Carbon::MONDAY)) as $week) {
                // Label with the days the bucket actually covers inside the range, e.g. "3–9 Mar", "28 Feb – 2 Mar"
                $first = $week->lt($start) ? $start->copy()->startOfDay() : $week->copy();
                $last = $week->copy()->addDays(6)->gt($end) ? $end->copy()->startOfDay() : $week->copy()->addDays(6);
                $label = match (true) {
                    $first->isSameDay($last) => $first->format('j M'),
                    $first->isSameMonth($last) => $first->format('j').'–'.$last->format('j M'),
                    default => $first->format('j M').' – '.$last->format('j M'),
                };
                $buckets[$week->format('Y-m-d')] = $empty($week->format('Y-m-d'), $multiYear ? $label.' '.$last->format('Y') : $label, $first->toDateString());
            }
        } else {
            foreach (CarbonPeriod::create($start->copy()->startOfDay(), '1 day', $end->copy()->startOfDay()) as $day) {
                $buckets[$day->format('Y-m-d')] = $empty($day->format('Y-m-d'), $day->format($multiYear ? 'j M Y' : 'j M'), $day->toDateString());
            }
        }

        foreach ($this->hourlyRows() as $hour => $row) {
            $local = Carbon::createFromFormat('Y-m-d H:i:s', $hour, 'UTC')->setTimezone($tz);
            $key = match ($granularity) {
                'month' => $local->format('Y-m'),
                'week' => $local->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'),
                default => $local->format('Y-m-d'),
            };
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['orders'] += $row['orders'];
            foreach (['gross_sales', 'discounts', 'shipping', 'tax', 'refunds', 'net'] as $field) {
                $buckets[$key][$field] = round($buckets[$key][$field] + $row[$field], 2);
            }
        }
        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['aov'] = $bucket['orders'] ? round($bucket['net'] / $bucket['orders'], 2) : 0.0;
        }

        return $buckets;
    }

    /**
     * Revenue and orders per UK-local hour of the range (24 buckets per day) – used for the "Today" chart.
     *
     * @return array<string, array{label:string, revenue:float, orders:int}>
     */
    public function hourlySeries(): array
    {
        $tz = LocalTime::TIMEZONE;
        $series = [];
        foreach (CarbonPeriod::create($this->from->copy()->setTimezone($tz), '1 hour', $this->until->copy()->setTimezone($tz)) as $hour) {
            $series[$hour->format('Y-m-d H')] = ['label' => $hour->format('H:00'), 'revenue' => 0.0, 'orders' => 0];
        }
        foreach ($this->hourlyRows() as $hour => $row) {
            $key = Carbon::createFromFormat('Y-m-d H:i:s', $hour, 'UTC')->setTimezone($tz)->format('Y-m-d H');
            if (isset($series[$key])) {
                $series[$key]['revenue'] = round($series[$key]['revenue'] + $row['net'], 2);
                $series[$key]['orders'] += $row['orders'];
            }
        }

        return $series;
    }

    /** @return array<string, array{orders:int, gross_sales:float, discounts:float, shipping:float, tax:float, refunds:float, net:float}> keyed by UTC "Y-m-d H:00:00" */
    protected function hourlyRows(): array
    {
        return $this->paidOrders()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as bucket, COUNT(*) as orders,
                COALESCE(SUM(subtotal),0) as gross_sales, COALESCE(SUM(discount_total),0) as discounts,
                COALESCE(SUM(shipping_total),0) as shipping, COALESCE(SUM(tax_total),0) as tax,
                COALESCE(SUM(refunded_total),0) as refunds, COALESCE(SUM(total - refunded_total),0) as net")
            ->groupBy('bucket')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->bucket => [
                'orders' => (int) $row->orders,
                'gross_sales' => (float) $row->gross_sales,
                'discounts' => (float) $row->discounts,
                'shipping' => (float) $row->shipping,
                'tax' => (float) $row->tax,
                'refunds' => (float) $row->refunds,
                'net' => (float) $row->net,
            ]])
            ->all();
    }

    /**
     * Best sellers. quantity = units sold minus units refunded; revenue = line totals (after discounts) for the
     * units that were not refunded.
     *
     * @return array<int|string, array{product_id:?int, name:string, sku:?string, quantity:int, revenue:float, orders:int}>
     */
    public function topProducts(int $limit = 20): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->whereBetween('orders.created_at', [$this->from, $this->until])
            ->selectRaw(Sql::qualify('order_items.product_id, MAX(order_items.name) as name, MAX(order_items.sku) as sku,
                SUM(order_items.quantity - order_items.refunded_quantity) as quantity,
                SUM(CASE WHEN order_items.quantity > 0 THEN order_items.total * (order_items.quantity - order_items.refunded_quantity) / order_items.quantity ELSE 0 END) as revenue,
                COUNT(DISTINCT orders.id) as orders', self::TABLES))
            ->groupBy('order_items.product_id', DB::raw(Sql::qualify('CASE WHEN order_items.product_id IS NULL THEN order_items.name END', self::TABLES)))
            ->orderByDesc('revenue')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn ($r, $i) => [($r->product_id ?: 'n'.$i) => [
                'product_id' => $r->product_id ? (int) $r->product_id : null,
                'name' => (string) $r->name,
                'sku' => $r->sku,
                'quantity' => (int) $r->quantity,
                'revenue' => round((float) $r->revenue, 2),
                'orders' => (int) $r->orders,
            ]])
            ->all();
    }

    /** Product sales grouped by each product's primary category (so nothing is counted twice). */
    public function byCategory(): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.primary_category_id')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->whereBetween('orders.created_at', [$this->from, $this->until])
            ->selectRaw(Sql::qualify('categories.id as category_id, MAX(categories.name) as name, MAX(categories.path) as path,
                SUM(order_items.quantity - order_items.refunded_quantity) as quantity,
                SUM(CASE WHEN order_items.quantity > 0 THEN order_items.total * (order_items.quantity - order_items.refunded_quantity) / order_items.quantity ELSE 0 END) as revenue,
                COUNT(DISTINCT orders.id) as orders', self::TABLES))
            ->groupBy('categories.id')
            ->orderByDesc('revenue')
            ->get();
        $total = max(0.01, (float) $rows->sum('revenue'));

        return $rows->mapWithKeys(fn ($r) => [($r->category_id ?: 'none') => [
            'category_id' => $r->category_id,
            'name' => $r->name ?: 'Uncategorised',
            'path' => $r->path,
            'quantity' => (int) $r->quantity,
            'orders' => (int) $r->orders,
            'revenue' => round((float) $r->revenue, 2),
            'share' => round((float) $r->revenue / $total * 100, 1),
        ]])->all();
    }

    /** @return list<array{code:?string, label:string, orders:int, gross:float, refunds:float, net:float, share:float}> */
    public function byPaymentMethod(): array
    {
        $rows = $this->paidOrders()
            ->selectRaw('payment_method, MAX(payment_method_title) as title, COUNT(*) as orders, COALESCE(SUM(total),0) as gross, COALESCE(SUM(refunded_total),0) as refunds, COALESCE(SUM(total - refunded_total),0) as net')
            ->groupBy('payment_method')
            ->orderByDesc('net')
            ->toBase()
            ->get();
        $total = max(0.01, (float) $rows->sum('net'));

        return $rows->map(fn ($r) => [
            'code' => $r->payment_method ?: null,
            'label' => $r->payment_method ? PaymentMethods::label($r->payment_method, $r->title) : 'No payment needed',
            'orders' => (int) $r->orders,
            'gross' => round((float) $r->gross, 2),
            'refunds' => round((float) $r->refunds, 2),
            'net' => round((float) $r->net, 2),
            'share' => round((float) $r->net / $total * 100, 1),
        ])->values()->all();
    }

    /**
     * New vs returning customers, identified by email address (guests included). A customer is "returning" when
     * they had a paid order before the start of the range.
     *
     * @return array{new: array{customers:int, orders:int, net:float}, returning: array{customers:int, orders:int, net:float}}
     */
    public function customerTypes(): array
    {
        $rows = $this->paidOrders()
            ->selectRaw('LOWER(TRIM(email)) as customer, COUNT(*) as orders, COALESCE(SUM(total - refunded_total),0) as net')
            ->groupByRaw('LOWER(TRIM(email))')
            ->toBase()
            ->get();

        $returning = collect();
        foreach ($rows->pluck('customer')->filter()->chunk(500) as $chunk) {
            $returning = $returning->merge(Order::query()
                ->whereIn('status', Order::PAID_STATUSES)
                ->where('created_at', '<', $this->from)
                ->whereIn(DB::raw('LOWER(TRIM(email))'), $chunk->values()->all())
                ->selectRaw('LOWER(TRIM(email)) as customer')
                ->distinct()
                ->toBase()
                ->pluck('customer'));
        }
        $returning = array_flip($returning->all());

        $result = ['new' => ['customers' => 0, 'orders' => 0, 'net' => 0.0], 'returning' => ['customers' => 0, 'orders' => 0, 'net' => 0.0]];
        foreach ($rows as $row) {
            $type = isset($returning[$row->customer]) ? 'returning' : 'new';
            $result[$type]['customers']++;
            $result[$type]['orders'] += (int) $row->orders;
            $result[$type]['net'] = round($result[$type]['net'] + (float) $row->net, 2);
        }

        return $result;
    }

    /** Customer accounts created in the range (dashboard "New customers"). */
    public function newCustomers(): int
    {
        return User::where('role', 'customer')->whereBetween('created_at', [$this->from, $this->until])->count();
    }

    public static function trend(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current > 0 ? 100.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
