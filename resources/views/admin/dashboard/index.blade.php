@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Home')

@php
    $compare = $config['compare'];
    $user = auth()->user();
    $ordersRoute = Route::has('admin.orders.index');
    $orderUrl = fn ($order) => Route::has('admin.orders.show') ? route('admin.orders.show', $order) : '#';
    $productUrl = fn ($id) => $id && Route::has('admin.products.edit') ? route('admin.products.edit', $id) : null;
@endphp

@section('content')
    <x-admin.page-header :title="$greeting.', '.($user->first_name ?: \Illuminate\Support\Str::before($user->name, ' ')).'.'"
                         :subtitle="'Here’s how '.setting('store.name', config('app.name')).' is doing.'">
        <x-slot:actions>
            <nav class="segmented" aria-label="Date range">
                @foreach ($ranges as $key => $option)
                    <a href="{{ route('admin.dashboard', $key === '30d' ? [] : ['range' => $key]) }}" @class(['segmented__item', 'is-active' => $range === $key]) @if ($range === $key) aria-current="page" @endif>{{ $option['label'] }}</a>
                @endforeach
            </nav>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="stack">
        @if ($totals['orders'] === 0)
            <x-admin.callout type="neutral" icon="calendar-days">
                No paid orders {{ $range === 'today' ? 'yet today' : 'in the last '.$config['days'].' days' }}.
                @if ($lastOrder)
                    The last one was <a href="{{ $orderUrl($lastOrder) }}">#{{ $lastOrder->number }}</a>
                    ({{ money($lastOrder->total) }}), {{ $lastOrder->created_at->diffForHumans() }}.
                    @if ($range !== '90d')<a href="{{ route('admin.dashboard', ['range' => '90d']) }}">See the last 90 days</a>.@endif
                @endif
            </x-admin.callout>
        @endif

        <div class="stats">
            <x-admin.stat label="Revenue" icon="banknotes" :value="money($stats['revenue']['value'])" :delta="$stats['revenue']['delta']" :hint="$stats['revenue']['hint']" />
            <x-admin.stat label="Orders" icon="shopping-bag" :value="number_format($stats['orders']['value'])" :delta="$stats['orders']['delta']" :hint="$stats['orders']['hint']"
                          :href="$ordersRoute ? route('admin.orders.index') : null" />
            <x-admin.stat label="Average order value" icon="calculator" :value="money($stats['aov']['value'])" :delta="$stats['aov']['delta']" :hint="$stats['aov']['hint']" />
            <x-admin.stat label="New customers" icon="user-plus" :value="number_format($stats['customers']['value'])" :delta="$stats['customers']['delta']" :hint="$stats['customers']['hint']"
                          :href="Route::has('admin.customers.index') ? route('admin.customers.index') : null" />
        </div>

        <x-admin.card x-data="salesChart" :title="$range === 'today' ? 'Sales today' : 'Sales over time'" :subtitle="'Paid orders, net of refunds · UK time'">
            <x-slot:actions>
                <div class="segmented" role="group" aria-label="Chart metric">
                    <button type="button" class="segmented__item" :class="{ 'is-active': metric === 'revenue' }" :aria-pressed="(metric === 'revenue').toString()" @click="show('revenue')">Revenue</button>
                    <button type="button" class="segmented__item" :class="{ 'is-active': metric === 'orders' }" :aria-pressed="(metric === 'orders').toString()" @click="show('orders')">Orders</button>
                </div>
            </x-slot:actions>
            <div class="legend mb-2" aria-hidden="true">
                <span class="legend__item"><span class="legend__swatch" style="background:#1976d2"></span>{{ $range === 'today' ? 'Today' : 'Last '.$config['days'].' days' }}</span>
                <span class="legend__item"><span class="legend__swatch" style="background:repeating-linear-gradient(90deg,#9a9a9a 0 4px,transparent 4px 7px);height:2px;border-radius:0"></span>{{ ucfirst($compare) }}</span>
            </div>
            <div class="chart-box">
                <canvas x-ref="canvas" role="img" aria-label="{{ ($range === 'today' ? 'Revenue by hour today' : 'Revenue per day, last '.$config['days'].' days').': '.money($totals['net']).' from '.$totals['orders'].' orders' }}"></canvas>
                @unless ($hasSales)
                    <div class="text-muted text-sm" style="position:absolute;inset:0;display:grid;place-items:center;pointer-events:none">No sales in this period</div>
                @endunless
            </div>
            <details class="mt-4">
                <summary class="text-sm link" style="cursor:pointer">View as table</summary>
                <div class="table-wrap mt-2" style="max-height:320px;overflow:auto">
                    <table class="table table--compact table--static-head">
                        <thead><tr><th scope="col">{{ $range === 'today' ? 'Hour' : 'Day' }}</th><th scope="col" class="num">Orders</th><th scope="col" class="num">Revenue</th><th scope="col" class="num">{{ ucfirst($compare) }}</th></tr></thead>
                        <tbody>
                            @foreach ($chart['labels'] as $i => $label)
                                <tr><td>{{ $label }}</td><td class="num">{{ $chart['orders'][$i] }}</td><td class="num">{{ money($chart['revenue'][$i]) }}</td><td class="num text-muted">{{ money($chart['previousRevenue'][$i] ?? 0) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </x-admin.card>

        <div class="layout">
            <div class="layout__main">
                <x-admin.card flush>
                    <x-slot:header>
                        <div class="flex-1">
                            <h2 class="card__title">Orders to fulfil <x-admin.badge :color="$toFulfilCount ? 'attention' : 'success'" class="ml-1">{{ $toFulfilCount }}</x-admin.badge></h2>
                            <p class="card__subtitle">Paid and waiting to be sent – oldest first</p>
                        </div>
                    </x-slot:header>
                    <x-slot:actions>
                        @if ($ordersRoute && $toFulfilCount)
                            <x-admin.button variant="plain" :href="route('admin.orders.index', ['status' => 'processing'])">View all</x-admin.button>
                        @endif
                    </x-slot:actions>
                    @if ($toFulfil->isEmpty())
                        <x-admin.empty icon="check-badge" title="All caught up" description="There are no paid orders waiting to be fulfilled." size="sm" />
                    @else
                        <ul class="list mt-2">
                            @foreach ($toFulfil as $order)
                                <li>
                                    <a href="{{ $orderUrl($order) }}" class="list__item">
                                        <span class="thumb thumb--sm"><x-admin.icon name="inbox-stack" /></span>
                                        <span class="list__main">
                                            <span class="list__title" style="display:block">#{{ $order->number }} · {{ $order->billing_name ?: $order->email }}</span>
                                            <span class="list__sub" style="display:block">{{ $order->items_count }} {{ \Illuminate\Support\Str::plural('item', $order->items_count) }}{{ $order->shipping_method_title ? ' · '.$order->shipping_method_title : '' }} · placed <x-admin.time :value="$order->created_at" format="relative" /></span>
                                        </span>
                                        <span class="list__meta fw-600">{{ money($order->total) }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-admin.card>

                <x-admin.card flush title="Top products" :subtitle="'Best sellers, '.($range === 'today' ? 'today' : 'last '.$config['days'].' days')">
                    @if (empty($top))
                        <x-admin.empty icon="trophy" title="No sales yet in this period" description="Best sellers will appear here once orders come in." size="sm" />
                    @else
                        <div class="mt-2">
                            <x-admin.table compact>
                                <x-slot:head>
                                    <th scope="col">Product</th>
                                    <th scope="col" class="num">Sold</th>
                                    <th scope="col" class="num">Revenue</th>
                                </x-slot:head>
                                @foreach ($top as $row)
                                    @php $product = $row['product_id'] ? $topProducts->get($row['product_id']) : null; @endphp
                                    <tr>
                                        <td>
                                            <div class="cell-main">
                                                <x-admin.thumb :src="$product?->images->first()?->path" size="sm" />
                                                @if ($url = $productUrl($product && ! $product->trashed() ? $product->id : null))
                                                    <a href="{{ $url }}" class="row-link truncate">{{ $row['name'] }}</a>
                                                @else
                                                    <span class="truncate">{{ $row['name'] }}</span>
                                                @endif
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

                @foreach (array_filter($widgets, fn ($w) => $w['wide']) as $key => $widget)
                    @include('commerce::admin.dashboard._widget', ['key' => $key, 'widget' => $widget])
                @endforeach
            </div>

            <div class="layout__aside">
                <x-admin.card title="Stock">
                    <div class="grid-2 gap-2">
                        <a href="{{ Route::has('admin.products.index') ? route('admin.products.index', ['stock' => 'outofstock']) : '#' }}" class="card card--subdued stat" style="box-shadow:none;color:inherit;text-decoration:none;padding:12px">
                            <span class="stat__label">Out of stock</span>
                            <span class="stat__value" style="font-size:20px">{{ number_format($outOfStockCount) }}</span>
                        </a>
                        <a href="{{ Route::has('admin.products.index') ? route('admin.products.index', ['stock' => 'low']) : '#' }}" class="card card--subdued stat" style="box-shadow:none;color:inherit;text-decoration:none;padding:12px">
                            <span class="stat__label">Low stock</span>
                            <span class="stat__value" style="font-size:20px">{{ number_format($lowStockCount) }}</span>
                        </a>
                    </div>
                    @if ($lowStock->isNotEmpty())
                        <p class="text-xs text-muted mt-4 mb-2">{{ $threshold }} or fewer left</p>
                        <ul class="list" style="margin:0 -16px -16px">
                            @foreach ($lowStock as $product)
                                <li>
                                    <a href="{{ $productUrl($product->id) ?? '#' }}" class="list__item">
                                        <x-admin.thumb :src="$product->images->first()?->path" size="sm" />
                                        <span class="list__main"><span class="list__title" style="display:block">{{ $product->name }}</span>@if ($product->sku)<span class="list__sub" style="display:block">SKU {{ $product->sku }}</span>@endif</span>
                                        <x-admin.badge color="warning">{{ $product->stock_quantity }} left</x-admin.badge>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-muted mt-4">No products are running low.</p>
                    @endif
                </x-admin.card>

                @if ($recovery)
                    <x-admin.card title="Abandoned baskets" :subtitle="'Reminder emails · '.$config['label']" data-dashboard-recovery>
                        <x-slot:actions><x-admin.button variant="plain" :href="route('admin.carts.index')">View</x-admin.button></x-slot:actions>
                        <div class="grid-2 gap-2">
                            <div class="card card--subdued stat" style="box-shadow:none;padding:12px">
                                <span class="stat__label">Recovered revenue</span>
                                <span class="stat__value" style="font-size:20px">{{ money($recovery['revenue']) }}</span>
                            </div>
                            <div class="card card--subdued stat" style="box-shadow:none;padding:12px">
                                <span class="stat__label">Recovered baskets</span>
                                <span class="stat__value" style="font-size:20px">{{ number_format($recovery['recovered']) }}</span>
                            </div>
                        </div>
                        <p class="text-xs text-muted mt-3">{{ number_format($recovery['emails']) }} {{ \Illuminate\Support\Str::plural('reminder', $recovery['emails']) }} sent, {{ number_format($recovery['clicks']) }} returned to their basket.@if (! $recovery['on']) Reminders are switched off.@endif</p>
                    </x-admin.card>
                @endif

                @if ($showMessages)
                <x-admin.card flush>
                    <x-slot:header>
                        <div class="flex-1">
                            <h2 class="card__title">Messages @if ($unreadCount)<x-admin.badge color="info" class="ml-1">{{ $unreadCount }} unread</x-admin.badge>@endif</h2>
                            <p class="card__subtitle">Latest contact-form submissions</p>
                        </div>
                    </x-slot:header>
                    <x-slot:actions>
                        @if (Route::has('admin.form-submissions.index'))
                            <x-admin.button variant="plain" :href="route('admin.form-submissions.index')">View all</x-admin.button>
                        @endif
                    </x-slot:actions>
                    @if ($submissions->isEmpty())
                        <x-admin.empty icon="envelope" title="No messages yet" size="sm" />
                    @else
                        <ul class="list mt-2">
                            @foreach ($submissions as $submission)
                                <li>
                                    <a href="{{ Route::has('admin.form-submissions.show') ? route('admin.form-submissions.show', $submission) : '#' }}" @class(['list__item', 'is-unread' => ! $submission->read_at])>
                                        <span class="avatar" style="background:#6b7280" aria-hidden="true">{{ \Pine\Commerce\View\Components\Admin\Ui::initials($submission->name ?: $submission->email) }}</span>
                                        <span class="list__main">
                                            <span class="list__title" style="display:block">{{ $submission->name ?: $submission->email ?: 'Anonymous' }}</span>
                                            <span class="list__sub" style="display:block">{{ $submission->subject ?: \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', (string) $submission->message)), 70) }}</span>
                                        </span>
                                        <span class="list__meta text-xs text-muted"><x-admin.time :value="$submission->created_at" format="relative" /></span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-admin.card>
                @endif

                @foreach (array_filter($widgets, fn ($w) => ! $w['wide']) as $key => $widget)
                    @include('commerce::admin.dashboard._widget', ['key' => $key, 'widget' => $widget])
                @endforeach
            </div>
        </div>
    </div>

    <script type="application/json" id="dashboard-chart">@json($chart)</script>
@endsection

@push('vendor')
    <script defer src="{{ commerce_admin_asset('vendor/chartjs/chart-4.5.1.umd.min.js', false) }}"></script>
@endpush

@push('scripts')
    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.data('salesChart', function () {
                var chart = null; // kept outside Alpine's reactive state on purpose
                var data = JSON.parse(document.getElementById('dashboard-chart').textContent);
                var compact = new Intl.NumberFormat('en-GB', { notation: 'compact', maximumFractionDigits: 1 });
                return {
                    metric: 'revenue',
                    init: function () {
                        if (!window.Chart) return;
                        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
                        Chart.defaults.color = '#616161';
                        this.render();
                    },
                    show: function (metric) { this.metric = metric; this.render(); },
                    render: function () {
                        var revenue = this.metric === 'revenue';
                        var format = function (v) { return revenue ? Admin.money(v) : (v + (v === 1 ? ' order' : ' orders')); };
                        if (chart) chart.destroy();
                        chart = new Chart(this.$refs.canvas, {
                            type: 'line',
                            data: {
                                labels: data.labels,
                                datasets: [
                                    { label: 'This period', data: revenue ? data.revenue : data.orders, borderColor: '#1976d2', backgroundColor: 'rgba(25,118,210,0.08)', fill: 'origin', borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, pointHitRadius: 12, tension: 0.3 },
                                    { label: 'Previous period', data: revenue ? data.previousRevenue : data.previousOrders, borderColor: '#9a9a9a', borderDash: [4, 3], borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 3, pointHitRadius: 12, fill: false, tension: 0.3 }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                animation: { duration: 250 },
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        backgroundColor: '#1f1f1f', padding: 10, cornerRadius: 8, boxPadding: 4, usePointStyle: true,
                                        callbacks: {
                                            title: function (items) {
                                                var i = items[0].dataIndex;
                                                return data.labels[i] + (data.previousLabels[i] ? '  vs  ' + data.previousLabels[i] : '');
                                            },
                                            label: function (ctx) { return ' ' + (ctx.datasetIndex === 0 ? 'This period' : 'Previous') + ': ' + format(ctx.parsed.y); }
                                        }
                                    }
                                },
                                scales: {
                                    x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0, font: { size: 11 } } },
                                    y: { beginAtZero: true, border: { display: false }, grid: { color: '#ededed' },
                                         ticks: { maxTicksLimit: 5, precision: 0, font: { size: 11 }, callback: function (v) { return revenue ? '£' + compact.format(v) : v; } } }
                                }
                            }
                        });
                    }
                };
            });
        });
    </script>
@endpush
