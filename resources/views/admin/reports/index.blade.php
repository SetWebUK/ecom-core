{{-- Analytics: date range (UK days) vs a comparison period, sales over time (chart + table), best sellers, categories,
     payment methods, new vs returning customers. Every table exports to CSV with the same range. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    use Pine\Commerce\Http\Controllers\Admin\ReportController;
    use Pine\Commerce\Services\Admin\SalesReport;

    $hasCompare = $compare !== null;
    $hasSales = $totals['orders'] > 0 || ($compareTotals['orders'] ?? 0) > 0;
    $productEdit = fn ($id) => $id && Route::has('admin.products.edit') ? route('admin.products.edit', $id) : null;
    $exportUrl = fn (string $table) => route('admin.reports.export', $query + ['table' => $table]);
    $compareHint = $hasCompare ? 'vs '.$compareLabel : null;
    $moneyStats = ['net', 'aov', 'gross_sales', 'discounts', 'refunds', 'shipping'];
    $customerTotal = max(1, $customers['new']['customers'] + $customers['returning']['customers']);
@endphp

@section('title', 'Analytics')

@include('commerce::admin.orders._assets')

@section('content')
    <x-admin.page-header title="Analytics" :subtitle="$rangeLabel.($hasCompare ? ' compared with '.$compareLabel : '')">
        <x-slot:actions>
            <x-admin.dropdown label="Export" icon="arrow-down-tray">
                @foreach (ReportController::TABLES as $table => $label)
                    <x-admin.dropdown-item :href="$exportUrl($table)" icon="table-cells">{{ $label }} (CSV)</x-admin.dropdown-item>
                @endforeach
            </x-admin.dropdown>
        </x-slot:actions>
    </x-admin.page-header>

    <form method="GET" action="{{ route('admin.reports.index') }}" class="report-toolbar" data-no-loading
          x-data="{ range: @js($preset) }" @change="if ($event.target.name === 'from' || $event.target.name === 'to' || ($event.target.name === 'range' && range === 'custom')) return; $el.requestSubmit()">
        <label class="sr-only" for="report-range">Date range</label>
        <select name="range" id="report-range" class="select" x-model="range">
            @foreach ($presets as $key => $label)
                <option value="{{ $key }}" @selected($preset === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <span class="date-range" x-show="range === 'custom'" @if ($preset !== 'custom') x-cloak @endif>
            <label class="sr-only" for="report-from">From</label>
            <input type="date" name="from" id="report-from" class="input" value="{{ $report->fromDate() }}" max="{{ \Pine\Commerce\Services\Admin\LocalTime::now()->toDateString() }}" :disabled="range !== 'custom'">
            <span class="date-range__sep" aria-hidden="true">–</span>
            <label class="sr-only" for="report-to">To</label>
            <input type="date" name="to" id="report-to" class="input" value="{{ $report->untilDate() }}" :disabled="range !== 'custom'">
            <button type="submit" class="btn btn--sm"><span>Apply</span></button>
        </span>
        <label class="sr-only" for="report-compare">Compare with</label>
        <select name="compare" id="report-compare" class="select">
            @foreach (ReportController::COMPARE as $key => $label)
                <option value="{{ $key }}" @selected($compareMode === $key)>{{ $key === 'none' ? $label : 'Compare: '.$label }}</option>
            @endforeach
        </select>
        <input type="hidden" name="by" value="{{ $granularity }}">
        <span class="flex-1"></span>
        <span class="text-xs text-muted">Paid orders · refunds subtracted · UK time</span>
    </form>

    <div class="stack">
        @if (! $hasSales)
            <x-admin.callout type="neutral" icon="calendar-days">
                No paid orders in {{ $rangeLabel }}{{ $hasCompare ? ' or in the comparison period' : '' }}.
                @if ($preset !== '12m')<a href="{{ route('admin.reports.index', ['range' => '12m']) }}">See the last 12 months</a>@endif
                @if ($preset !== 'last_year') · <a href="{{ route('admin.reports.index', ['range' => 'last_year']) }}">Last year</a>@endif
            </x-admin.callout>
        @endif

        <div class="stats stats--8">
            @foreach ($stats as $key => $stat)
                <x-admin.stat :label="$stat['label']"
                              :value="in_array($key, $moneyStats, true) ? money($stat['value']) : number_format($stat['value'])"
                              :delta="$stat['delta']" :invert="in_array($key, ['refunds', 'discounts'], true)"
                              :hint="$hasCompare ? ($stat['previous'] !== null ? (in_array($key, $moneyStats, true) ? money($stat['previous']) : number_format($stat['previous'])).' before' : null) : null" />
            @endforeach
        </div>

        {{-- Sales over time ------------------------------------------------------------------------ --}}
        <x-admin.card x-data="reportChart('report-chart', {{ $hasCompare ? 'true' : 'false' }})" title="Sales over time" :subtitle="'By '.$granularity.' · '.$rangeLabel">
            <x-slot:actions>
                <div class="segmented" role="group" aria-label="Chart shows">
                    <button type="button" class="segmented__item" :class="{ 'is-active': metric === 'net' }" :aria-pressed="(metric === 'net').toString()" @click="show('net')">Net sales</button>
                    <button type="button" class="segmented__item" :class="{ 'is-active': metric === 'orders' }" :aria-pressed="(metric === 'orders').toString()" @click="show('orders')">Orders</button>
                    <button type="button" class="segmented__item hidden-mobile" :class="{ 'is-active': metric === 'aov' }" :aria-pressed="(metric === 'aov').toString()" @click="show('aov')">Avg. order</button>
                </div>
                <nav class="segmented hidden-mobile" aria-label="Group by">
                    @foreach (SalesReport::GRANULARITIES as $key => $label)
                        <a href="{{ route('admin.reports.index', array_merge($query, ['by' => $key])) }}" @class(['segmented__item', 'is-active' => $granularity === $key]) @if ($granularity === $key) aria-current="true" @endif>{{ $label }}</a>
                    @endforeach
                </nav>
            </x-slot:actions>
            <div class="legend mb-2" aria-hidden="true">
                <span class="legend__item"><span class="legend__swatch" style="background:#1976d2"></span>{{ $rangeLabel }}</span>
                @if ($hasCompare)
                    <span class="legend__item"><span class="legend__swatch" style="background:repeating-linear-gradient(90deg,#9a9a9a 0 4px,transparent 4px 7px);height:2px;border-radius:0"></span>{{ $compareLabel }}</span>
                @endif
            </div>
            <div class="chart-box">
                <canvas x-ref="canvas" role="img" aria-label="Net sales by {{ $granularity }}, {{ $rangeLabel }}: {{ money($totals['net']) }} from {{ $totals['orders'] }} orders. The same figures are in the table below."></canvas>
                @unless ($hasSales)
                    <div class="text-muted text-sm" style="position:absolute;inset:0;display:grid;place-items:center;pointer-events:none">No sales in this period</div>
                @endunless
            </div>

            <div class="row row--between mt-4 mb-2">
                <h3 class="card__section-title" style="margin:0">Table</h3>
                <x-admin.button size="sm" variant="plain" icon="arrow-down-tray" :href="$exportUrl('sales')">Export CSV</x-admin.button>
            </div>
            <div class="table-scroll" style="margin:0 calc(var(--s-4) * -1) calc(var(--s-4) * -1)">
                <table class="table table--compact table--static-head">
                    <thead>
                        <tr>
                            <th scope="col">{{ ucfirst($granularity) }}</th>
                            <th scope="col" class="num">Orders</th>
                            <th scope="col" class="num">Gross sales</th>
                            <th scope="col" class="num">Discounts</th>
                            <th scope="col" class="num">Shipping</th>
                            @if ($hasTax)<th scope="col" class="num">Tax</th>@endif
                            <th scope="col" class="num">Refunds</th>
                            <th scope="col" class="num">Net sales</th>
                            <th scope="col" class="num">Avg. order</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse($buckets) as $bucket)
                            <tr @class(['is-muted' => $bucket['orders'] === 0])>
                                <td class="nowrap">{{ $bucket['label'] }}</td>
                                <td class="num">{{ number_format($bucket['orders']) }}</td>
                                <td class="num">{{ money($bucket['gross_sales']) }}</td>
                                <td class="num">{{ $bucket['discounts'] > 0 ? '−'.money($bucket['discounts']) : money(0) }}</td>
                                <td class="num">{{ money($bucket['shipping']) }}</td>
                                @if ($hasTax)<td class="num">{{ money($bucket['tax']) }}</td>@endif
                                <td class="num">{{ $bucket['refunds'] > 0 ? '−'.money($bucket['refunds']) : money(0) }}</td>
                                <td class="num fw-600">{{ money($bucket['net']) }}</td>
                                <td class="num">{{ money($bucket['aov']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="num">{{ number_format($totals['orders']) }}</td>
                            <td class="num">{{ money($totals['gross_sales']) }}</td>
                            <td class="num">{{ $totals['discounts'] > 0 ? '−'.money($totals['discounts']) : money(0) }}</td>
                            <td class="num">{{ money($totals['shipping']) }}</td>
                            @if ($hasTax)<td class="num">{{ money($totals['tax']) }}</td>@endif
                            <td class="num">{{ $totals['refunds'] > 0 ? '−'.money($totals['refunds']) : money(0) }}</td>
                            <td class="num">{{ money($totals['net']) }}</td>
                            <td class="num">{{ money($totals['aov']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-admin.card>

        <div class="layout layout--equal">
            <div class="layout__main">
                {{-- Top products --}}
                <x-admin.card flush title="Top products" subtitle="By net sales">
                    <x-slot:actions><x-admin.button size="sm" variant="plain" icon="arrow-down-tray" :href="$exportUrl('products')">CSV</x-admin.button></x-slot:actions>
                    @if (empty($top))
                        <x-admin.empty icon="trophy" title="No products sold in this period" size="sm" />
                    @else
                        <div class="mt-2">
                            <x-admin.table compact>
                                <x-slot:head>
                                    <th scope="col">Product</th>
                                    <th scope="col" class="num">Sold</th>
                                    <th scope="col" class="num">Net sales</th>
                                </x-slot:head>
                                @foreach ($top as $row)
                                    @php $product = $row['product_id'] ? $products->get($row['product_id']) : null; @endphp
                                    <tr>
                                        <td style="max-width:360px">
                                            <div class="cell-main">
                                                <x-admin.thumb :src="$product?->images->first()?->path" size="sm" />
                                                <div style="min-width:0">
                                                    @if ($url = $productEdit($product && ! $product->trashed() ? $product->id : null))
                                                        <a href="{{ $url }}" class="row-link truncate" style="display:block">{{ $row['name'] }}</a>
                                                    @else
                                                        <span class="truncate" style="display:block">{{ $row['name'] }}</span>
                                                    @endif
                                                    <span class="cell-sub">{{ $row['orders'] }} {{ \Illuminate\Support\Str::plural('order', $row['orders']) }}{{ $row['sku'] ? ' · '.$row['sku'] : '' }}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="num">{{ number_format($row['quantity']) }}</td>
                                        <td class="num">{{ money($row['revenue']) }}</td>
                                    </tr>
                                @endforeach
                            </x-admin.table>
                        </div>
                    @endif
                </x-admin.card>

                {{-- New vs returning --}}
                <x-admin.card title="Customers" subtitle="New = first paid order in this period">
                    <x-slot:actions><x-admin.button size="sm" variant="plain" icon="arrow-down-tray" :href="$exportUrl('customers')">CSV</x-admin.button></x-slot:actions>
                    <div class="split-stat">
                        @foreach (['new' => 'New customers', 'returning' => 'Returning customers'] as $type => $label)
                            <div class="split-stat__item">
                                <div class="stat__label">{{ $label }}</div>
                                <div class="split-stat__value">{{ number_format($customers[$type]['customers']) }}</div>
                                <div class="text-xs text-muted">{{ round($customers[$type]['customers'] / $customerTotal * 100) }}% of customers · {{ number_format($customers[$type]['orders']) }} {{ \Illuminate\Support\Str::plural('order', $customers[$type]['orders']) }} · {{ money($customers[$type]['net']) }}</div>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-muted mt-3">Customers are matched by email address, so guest checkouts count too. {{ number_format($newAccounts) }} new {{ \Illuminate\Support\Str::plural('account', $newAccounts) }} {{ $newAccounts === 1 ? 'was' : 'were' }} created in this period.</p>
                </x-admin.card>
            </div>

            <div class="layout__aside">
                {{-- Categories --}}
                <x-admin.card flush title="Sales by category" subtitle="Each product counted once, under its main category">
                    <x-slot:actions><x-admin.button size="sm" variant="plain" icon="arrow-down-tray" :href="$exportUrl('categories')">CSV</x-admin.button></x-slot:actions>
                    @if (empty($categories))
                        <x-admin.empty icon="squares-2x2" title="No sales in this period" size="sm" />
                    @else
                        <div class="mt-2">
                            <x-admin.table compact>
                                <x-slot:head>
                                    <th scope="col">Category</th>
                                    <th scope="col" class="num">Sold</th>
                                    <th scope="col" class="num">Net sales</th>
                                    <th scope="col" class="num hidden-mobile">Share</th>
                                </x-slot:head>
                                @foreach ($categories as $row)
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td class="num">{{ number_format($row['quantity']) }}</td>
                                        <td class="num">{{ money($row['revenue']) }}</td>
                                        <td class="num hidden-mobile">
                                            <span class="share-cell">
                                                <span class="progress" role="presentation"><span class="progress__bar" style="display:block;width:{{ max(0, min(100, $row['share'])) }}%"></span></span>
                                                <span class="text-muted" style="min-width:42px">{{ $row['share'] }}%</span>
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </x-admin.table>
                        </div>
                    @endif
                </x-admin.card>

                {{-- Payment methods --}}
                <x-admin.card flush title="Sales by payment method">
                    <x-slot:actions><x-admin.button size="sm" variant="plain" icon="arrow-down-tray" :href="$exportUrl('payments')">CSV</x-admin.button></x-slot:actions>
                    @if (empty($payments))
                        <x-admin.empty icon="credit-card" title="No payments in this period" size="sm" />
                    @else
                        <div class="mt-2">
                            <x-admin.table compact>
                                <x-slot:head>
                                    <th scope="col">Method</th>
                                    <th scope="col" class="num">Orders</th>
                                    <th scope="col" class="num">Refunds</th>
                                    <th scope="col" class="num">Net sales</th>
                                </x-slot:head>
                                @foreach ($payments as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td class="num">{{ number_format($row['orders']) }}</td>
                                        <td class="num">{{ $row['refunds'] > 0 ? '−'.money($row['refunds']) : '—' }}</td>
                                        <td class="num">{{ money($row['net']) }} <span class="text-subtle text-xs">({{ $row['share'] }}%)</span></td>
                                    </tr>
                                @endforeach
                            </x-admin.table>
                        </div>
                    @endif
                </x-admin.card>

                @if (! empty($taxRates))
                    {{-- Tax by rate (Settings › Tax) --}}
                    <x-admin.card flush title="Tax by rate" subtitle="Tax on items and shipping of paid orders">
                        <x-slot:actions><x-admin.button size="sm" variant="plain" icon="arrow-down-tray" :href="$exportUrl('taxes')">CSV</x-admin.button></x-slot:actions>
                        <div class="mt-2">
                            <x-admin.table compact>
                                <x-slot:head>
                                    <th scope="col">Tax</th>
                                    <th scope="col" class="num">Orders</th>
                                    <th scope="col" class="num">Shipping</th>
                                    <th scope="col" class="num">Total</th>
                                </x-slot:head>
                                @foreach ($taxRates as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td class="num">{{ number_format($row['orders']) }}</td>
                                        <td class="num">{{ money($row['shipping_tax']) }}</td>
                                        <td class="num">{{ money($row['total']) }}</td>
                                    </tr>
                                @endforeach
                            </x-admin.table>
                        </div>
                    </x-admin.card>
                @endif
            </div>
        </div>

        <details class="card" style="padding:var(--s-4)">
            <summary class="fw-600" style="cursor:pointer">How these numbers are worked out</summary>
            <ul class="summary-list mt-3">
                <li>Only paid orders count: Processing, Completed and On hold. Pending, failed, cancelled, fully refunded and deleted orders are left out.</li>
                <li>Orders count on the day they were placed (UK time), including any refund made later.</li>
                <li>Gross sales = products before discounts. Net sales = what customers paid (after discounts, including shipping{{ $hasTax ? ' and tax' : '' }}) minus refunds – the same as “Revenue” on Home.</li>
                <li>Average order = net sales ÷ orders. Items sold excludes refunded items.</li>
            </ul>
        </details>
    </div>

    <script type="application/json" id="report-chart">@json($chart)</script>
@endsection

@push('vendor')
    <script defer src="{{ commerce_admin_asset('vendor/chartjs/chart-4.5.1.umd.min.js', false) }}"></script>
@endpush
