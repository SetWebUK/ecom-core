{{-- Customers list: search, filters, order stats computed in SQL (orders, total spent, last order), sortable, bulk marketing + export. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    use Pine\Commerce\Services\Admin\CustomerQuery;
    use Pine\Commerce\View\Components\Admin\Ui;
@endphp

@section('title', 'Customers')

@include('commerce::admin.orders._assets')

@section('content')
    <x-admin.page-header title="Customers" :subtitle="$total !== null ? number_format($total).' customers' : null">
        <x-slot:actions>
            <x-admin.button icon="arrow-down-tray" :href="route('admin.customers.export', $exportQuery)">Export</x-admin.button>
            <x-admin.button variant="primary" icon="user-plus" :href="route('admin.customers.create')">Add customer</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($total === 0)
        <div class="card">
            <x-admin.empty icon="user-group" title="No customers yet" description="Customers are added when they create an account or place an order. You can also add one yourself.">
                <x-admin.button variant="primary" icon="user-plus" :href="route('admin.customers.create')">Add customer</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush>
            <x-admin.filters placeholder="Search by name, email, phone, company or postcode" :chips="$chips">
                <x-admin.filter-select name="orders" :options="CustomerQuery::HAS_ORDERS" placeholder="Any orders" label="Orders" />
                <x-admin.filter-select name="account" :options="CustomerQuery::ACCOUNT" placeholder="Any account" label="Account type" />
                <x-admin.filter-select name="marketing" :options="CustomerQuery::MARKETING" placeholder="Any marketing" label="Email marketing" />
            </x-admin.filters>

            @if ($customers->isEmpty())
                <x-admin.empty icon="magnifying-glass" title="No customers match" description="Try a different search or filter." size="sm">
                    <x-admin.button :href="route('admin.customers.index')">Clear filters</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.table :ids="$customers->pluck('id')" selectable :bulk-action="route('admin.customers.bulk')" stack>
                    <x-slot:bulk>
                        <x-admin.button size="sm" icon="arrow-down-tray" x-on:click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.customers.export')) }} + '?ids=' + selected.join(',')">Export</x-admin.button>
                        <x-admin.button type="submit" name="action" value="subscribe" size="sm"
                                        data-confirm-title="Mark as subscribed to email marketing?" data-confirm="Only do this if these customers have agreed to receive marketing emails." data-confirm-button="Mark as subscribed" data-confirm-danger="false">Subscribe to marketing</x-admin.button>
                        <x-admin.button type="submit" name="action" value="unsubscribe" size="sm">Unsubscribe</x-admin.button>
                    </x-slot:bulk>
                    <x-slot:head>
                        <x-admin.th sort="name">Customer</x-admin.th>
                        <x-admin.th class="hidden-mobile">Location</x-admin.th>
                        <x-admin.th sort="orders_count" align="right" first="desc">Orders</x-admin.th>
                        <x-admin.th sort="total_spent" align="right" first="desc">Total spent</x-admin.th>
                        <x-admin.th sort="last_order_at" first="desc">Last order</x-admin.th>
                        <x-admin.th class="hidden-mobile">Account</x-admin.th>
                        <x-admin.th sort="created_at" first="desc" class="hidden-mobile">Customer since</x-admin.th>
                    </x-slot:head>
                    @foreach ($customers as $customer)
                        @php
                            $url = route('admin.customers.show', $customer);
                            $name = $customer->full_name ?: $customer->email;
                        @endphp
                        <tr data-href="{{ $url }}" @class(['is-muted' => ! $customer->is_active])>
                            <x-admin.row-check :id="$customer->id" :label="'Select '.$name" />
                            <td class="stack-title" style="max-width:320px">
                                <div class="cell-main">
                                    <span class="avatar hidden-mobile" style="background:#6b7280" aria-hidden="true">{{ Ui::initials($name) }}</span>
                                    <div class="min-w-0" style="min-width:0">
                                        <a href="{{ $url }}" class="row-link truncate" style="display:block">{{ $name }}</a>
                                        <div class="cell-sub truncate">{{ $customer->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden-mobile text-muted" data-label="Location">{{ CustomerQuery::location($customer->location) ?? '—' }}</td>
                            <td class="num" data-label="Orders">{{ number_format((int) $customer->orders_count) }}</td>
                            <td class="num" data-label="Total spent">{{ money((float) $customer->total_spent) }}</td>
                            <td class="nowrap" data-label="Last order">
                                @if ($customer->last_order_at)
                                    <x-admin.time :value="$customer->last_order_at" format="date" />
                                @else
                                    <span class="text-subtle">No orders</span>
                                @endif
                            </td>
                            <td class="hidden-mobile" data-label="Account">
                                <span class="row gap-1">
                                    @if (! $customer->is_active)
                                        <x-admin.badge color="danger" size="sm">Disabled</x-admin.badge>
                                    @elseif ($customer->password)
                                        <x-admin.badge color="info" size="sm">Registered</x-admin.badge>
                                    @else
                                        <x-admin.badge color="gray" size="sm">Guest</x-admin.badge>
                                    @endif
                                    @if ($customer->marketing_opt_in)
                                        <x-admin.badge color="success" size="sm" icon="envelope" title="Subscribed to email marketing">Subscribed</x-admin.badge>
                                    @endif
                                </span>
                            </td>
                            <td class="hidden-mobile text-muted nowrap" data-label="Customer since"><x-admin.time :value="$customer->created_at" format="date" /></td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$customers" />
            @endif
        </x-admin.card>
        <p class="text-xs text-muted mt-3">Orders include guest checkouts placed with the same email address. Total spent counts paid orders, minus refunds.</p>
    @endif
@endsection
