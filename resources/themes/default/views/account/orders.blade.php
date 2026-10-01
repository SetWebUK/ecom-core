@extends('account.frame')

@section('account_content')
<h1 class="page-title">Orders</h1>
@if ($orders->isEmpty())
    <div class="empty">
        <p class="empty__title">No orders yet</p>
        <a class="btn btn--primary" href="{{ url('shop') }}/">Start shopping</a>
    </div>
@else
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th scope="col">Order</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col">Total</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                @foreach ($orders as $order)
                    @php $qty = (int) ($order->items_sum_quantity ?? 0); @endphp
                    <tr>
                        <th scope="row"><a href="{{ route('account.order', ['number' => $order->number]) }}">#{{ $order->number }}</a></th>
                        <td data-title="Date"><time datetime="{{ $order->created_at?->toAtomString() }}">{{ \Pine\Commerce\Services\Checkout\UkTime::format($order->created_at, 'j M Y') }}</time></td>
                        <td data-title="Status"><span class="status-pill status-pill--{{ $order->status }}">{{ $order->status_label }}</span></td>
                        <td data-title="Total">{{ money($order->total) }} <span class="muted">· {{ $qty }} {{ \Illuminate\Support\Str::plural('item', $qty) }}</span></td>
                        <td class="table__actions">
                            @if (in_array($order->status, ['pending', 'failed'], true) && (float) $order->total > 0 && $order->created_via === 'checkout')
                                <a class="btn btn--primary btn--sm" href="{{ route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]) }}">Pay</a>
                            @endif
                            <a class="btn btn--outline btn--sm" href="{{ route('account.order', ['number' => $order->number]) }}">View</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($orders->lastPage() > 1)
        <nav class="pagination" aria-label="Orders pages">
            @if ($orders->currentPage() > 1)<a class="btn btn--outline btn--sm" href="{{ $orders->currentPage() === 2 ? route('account.orders') : route('account.orders.page', ['page' => $orders->currentPage() - 1]) }}">Previous</a>@endif
            @if ($orders->hasMorePages())<a class="btn btn--outline btn--sm" href="{{ route('account.orders.page', ['page' => $orders->currentPage() + 1]) }}">Next</a>@endif
        </nav>
    @endif
@endif
@endsection
