{{-- Orders list: status tabs (To fulfil first), search, filters, sortable columns, bulk status / print / export. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    use Pine\Commerce\Services\Admin\OrderFilters;
    use Pine\Commerce\Services\Admin\PaymentMethods;
    use Illuminate\Support\Str;

    $emptyTab = [
        'processing' => ['icon' => 'check-badge', 'title' => 'All caught up', 'text' => 'There are no paid orders waiting to be sent.'],
        'on-hold' => ['icon' => 'pause-circle', 'title' => 'No orders on hold', 'text' => 'Orders waiting for a bank transfer or a check appear here.'],
        'pending' => ['icon' => 'clock', 'title' => 'No orders awaiting payment', 'text' => 'Orders where the customer hasn’t finished paying appear here.'],
    ][$status] ?? ['icon' => 'inbox', 'title' => 'No '.Str::lower($tabs[$status]['label']).' orders', 'text' => null];
@endphp

@section('title', 'Orders')

@include('commerce::admin.orders._assets')

@section('content')
    <x-admin.page-header title="Orders" :subtitle="$totalOrders !== null ? number_format($totalOrders).' orders in total' : null">
        <x-slot:actions>
            @if (commerce_feature('abandoned_carts', false))
                <x-admin.button :href="route('admin.carts.index')" icon="shopping-cart" class="hidden-mobile">Abandoned checkouts</x-admin.button>
            @endif
            <x-admin.dropdown label="Export" icon="arrow-down-tray">
                <div class="dropdown__label">{{ $filters->isFiltered() || $status !== 'all' ? 'Orders matching this view' : 'All orders' }}</div>
                <x-admin.dropdown-item :href="route('admin.orders.export', $exportQuery + ['format' => 'orders'])" icon="table-cells">CSV – one row per order</x-admin.dropdown-item>
                <x-admin.dropdown-item :href="route('admin.orders.export', $exportQuery)" icon="queue-list">CSV – one row per product</x-admin.dropdown-item>
            </x-admin.dropdown>
            <x-admin.button variant="primary" icon="plus" :href="route('admin.orders.create')">Create order</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($totalOrders === 0)
        <div class="card">
            <x-admin.empty icon="inbox-stack" title="No orders yet" description="Orders placed on the website appear here. You can also create an order for a phone or email sale.">
                <x-admin.button variant="primary" icon="plus" :href="route('admin.orders.create')">Create order</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush>
            <x-admin.status-tabs :tabs="$tabs" :current="$status" default="__none" />
            <x-admin.filters placeholder="Search orders by number, name, email, postcode, phone or SKU" :chips="$chips" keep="status">
                <div class="date-range" role="group" aria-label="Order date">
                    <label class="sr-only" for="filter-from">From date</label>
                    <input type="date" name="from" id="filter-from" class="input" value="{{ $filters->from }}" max="{{ \Pine\Commerce\Services\Admin\LocalTime::now()->toDateString() }}" title="From date">
                    <span class="date-range__sep" aria-hidden="true">–</span>
                    <label class="sr-only" for="filter-to">To date</label>
                    <input type="date" name="to" id="filter-to" class="input" value="{{ $filters->to }}" title="To date">
                </div>
                <x-admin.filter-select name="payment" :options="$paymentOptions" placeholder="Any payment method" label="Payment method" />
                <x-admin.filter-select name="refunds" :options="OrderFilters::REFUND_FILTERS" placeholder="Refunds: any" label="Refunds" />
                <x-admin.filter-select name="via" :options="OrderFilters::CREATED_VIA" placeholder="Any source" label="Created via" class="hidden-mobile" />
            </x-admin.filters>

            @if ($orders->isEmpty())
                @if ($filters->isFiltered())
                    <x-admin.empty icon="magnifying-glass" title="No orders match" description="Try a different search, change the filters or look in another tab." size="sm">
                        <x-admin.button :href="route('admin.orders.index', ['status' => 'all'])">Clear filters</x-admin.button>
                    </x-admin.empty>
                @else
                    <x-admin.empty :icon="$emptyTab['icon']" :title="$emptyTab['title']" :description="$emptyTab['text']" size="sm">
                        <x-admin.button :href="route('admin.orders.index', ['status' => 'all'])">View all orders</x-admin.button>
                    </x-admin.empty>
                @endif
            @else
                <x-admin.table :ids="$orders->pluck('id')" selectable :bulk-action="route('admin.orders.bulk')" stack>
                    <x-slot:bulk>
                        <x-admin.button type="submit" name="action" value="completed" size="sm" icon="check-circle"
                                        data-confirm-title="Mark the selected orders as completed?"
                                        data-confirm="Each customer gets their “order complete” email. Use the order page to add tracking numbers first."
                                        data-confirm-button="Mark as completed" data-confirm-danger="false">Mark as completed</x-admin.button>
                        <x-admin.button type="submit" name="action" value="processing" size="sm"
                                        data-confirm-title="Mark the selected orders as processing (paid)?"
                                        data-confirm="Only do this once the payment has arrived. Customers get their “order received” email."
                                        data-confirm-button="Mark as processing" data-confirm-danger="false">Mark as processing</x-admin.button>
                        <x-admin.button type="submit" name="action" value="on-hold" size="sm"
                                        data-confirm-title="Put the selected orders on hold?" data-confirm-button="Put on hold" data-confirm-danger="false">Put on hold</x-admin.button>
                        <x-admin.dropdown label="Print" icon="printer" size="sm" align="left">
                            <x-admin.dropdown-item icon="clipboard-document-list" x-on:click="Sales.print({{ \Illuminate\Support\Js::from(route('admin.print', ['document' => 'packing-slip'])) }}, selected)">Packing slips</x-admin.dropdown-item>
                            <x-admin.dropdown-item icon="document-text" x-on:click="Sales.print({{ \Illuminate\Support\Js::from(route('admin.print', ['document' => 'invoice'])) }}, selected)">Invoices</x-admin.dropdown-item>
                            <div class="dropdown__sep"></div>
                            <x-admin.dropdown-item icon="document-arrow-down" x-on:click="Sales.print({{ \Illuminate\Support\Js::from(route('admin.pdf', ['document' => 'invoice'])) }}, selected)">Invoices – one PDF</x-admin.dropdown-item>
                            <x-admin.dropdown-item icon="archive-box-arrow-down" x-on:click="Sales.print({{ \Illuminate\Support\Js::from(route('admin.pdf', ['document' => 'invoice', 'format' => 'zip'])) }}, selected)">Invoices – ZIP of PDFs</x-admin.dropdown-item>
                            <x-admin.dropdown-item icon="document-arrow-down" x-on:click="Sales.print({{ \Illuminate\Support\Js::from(route('admin.pdf', ['document' => 'packing-slip'])) }}, selected)">Packing slips – one PDF</x-admin.dropdown-item>
                        </x-admin.dropdown>
                        <x-admin.button size="sm" icon="arrow-down-tray" x-on:click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.orders.export', ['status' => 'all', 'format' => 'orders'])) }} + '&ids=' + selected.join(',')">Export</x-admin.button>
                    </x-slot:bulk>
                    <x-slot:head>
                        <x-admin.th sort="number" first="desc">Order</x-admin.th>
                        <x-admin.th sort="created_at" first="desc">Date</x-admin.th>
                        <x-admin.th>Customer</x-admin.th>
                        <x-admin.th>Status</x-admin.th>
                        <x-admin.th align="right" class="hidden-mobile">Items</x-admin.th>
                        <x-admin.th sort="total" align="right" first="desc">Total</x-admin.th>
                        <x-admin.th class="hidden-mobile">Payment</x-admin.th>
                        <x-admin.th class="hidden-mobile">Delivery</x-admin.th>
                    </x-slot:head>
                    @foreach ($orders as $order)
                        @php
                            $url = route('admin.orders.show', $order);
                            $refunded = (float) $order->refunded_total > 0;
                        @endphp
                        <tr data-href="{{ $url }}">
                            <x-admin.row-check :id="$order->id" :label="'Select order #'.$order->number" />
                            <td class="stack-title nowrap">
                                <a href="{{ $url }}" class="row-link">#{{ $order->number }}</a>
                                @if ($order->customer_note)
                                    <span title="The customer left a note" class="text-muted" style="display:inline-block;vertical-align:-3px"><x-admin.icon name="chat-bubble-bottom-center-text" size="sm" label="Has a customer note" /></span>
                                @endif
                                @if ($order->created_via === 'admin')
                                    <x-admin.badge color="outline" size="sm" title="Created by staff">Staff</x-admin.badge>
                                @endif
                            </td>
                            <td class="nowrap" data-label="Date"><x-admin.time :value="$order->created_at" /></td>
                            <td data-label="Customer" style="max-width:260px">
                                <div class="truncate fw-500">{{ $order->billing_name ?: ($order->billing_company ?: $order->email) }}</div>
                                <div class="cell-sub truncate hidden-mobile">{{ $order->email }}</div>
                            </td>
                            <td data-label="Status"><x-admin.status-badge :status="$order->status" /></td>
                            <td class="num hidden-mobile" data-label="Items">{{ (int) $order->item_quantity }}</td>
                            <td class="num" data-label="Total">
                                @if ($refunded)
                                    <span class="money-was" title="Original total">{{ money($order->total) }}</span>
                                    <span title="{{ money($order->refunded_total) }} refunded">{{ money((float) $order->total - (float) $order->refunded_total) }}</span>
                                @else
                                    {{ money($order->total) }}
                                @endif
                            </td>
                            <td class="hidden-mobile" data-label="Payment" style="max-width:180px"><span class="truncate text-muted" style="display:block">{{ PaymentMethods::label($order->payment_method, $order->payment_method_title) }}</span></td>
                            <td class="hidden-mobile text-muted" data-label="Delivery" style="max-width:160px"><span class="truncate" style="display:block">{{ $order->shipping_method_title ?: '—' }}</span></td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$orders" />
            @endif
        </x-admin.card>
        <p class="text-xs text-muted mt-3">Tip: press <kbd>/</kbd> to search. Click a row to open the order; hold <kbd>Ctrl</kbd> (<kbd>⌘</kbd> on a Mac) to open it in a new tab.</p>
    @endif
@endsection
