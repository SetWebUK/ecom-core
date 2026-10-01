{{--
    Packing slip PDF (dompdf) – no prices, safe to put in the box. One page per order in $orders.
    Override in a theme (views/pdf/packing-slip.blade.php) or the app (resources/views/pdf/packing-slip.blade.php).
--}}
@php
    use Pine\Commerce\Services\Admin\LocalTime;
    use Pine\Commerce\Services\Invoices\DocumentData;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', config('commerce.store.locale', 'en_GB')) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $orders->count() === 1 ? 'Packing slip #'.$orders->first()->number : $orders->count().' packing slips' }} – {{ $store['name'] }}</title>
    @include('commerce::pdf._styles')
</head>
<body>
@foreach ($orders as $order)
    @php
        $shipping = DocumentData::address($order, 'shipping');
        $deliver = $shipping !== '' ? $shipping : DocumentData::address($order, 'billing');
        $phone = $order->shipping_phone ?: $order->phone;
        $toSend = $order->items->sum(fn ($i) => max(0, $i->quantity - $i->refunded_quantity));
    @endphp
    <div class="doc{{ $loop->last ? ' doc--last' : '' }}">
        <table class="head">
            <tr>
                <td>
                    @if ($logo)<img class="logo" src="{{ $logo }}" alt="{{ $store['name'] }}">@else<span class="store-name">{{ $store['name'] }}</span>@endif
                </td>
                <td style="text-align:right">
                    <h1>Packing slip</h1>
                    <div class="big-number">#{{ $order->number }}</div>
                    <table class="facts">
                        <tr><td class="k">Order date</td><td class="v">{{ LocalTime::format($order->created_at, 'j F Y') }}</td></tr>
                        <tr><td class="k">Delivery</td><td class="v">{{ $order->shipping_method_title ?: '—' }}</td></tr>
                        @if ($order->tracking_number)<tr><td class="k">Tracking</td><td class="v">{{ trim($order->tracking_carrier.' '.$order->tracking_number) }}</td></tr>@endif
                    </table>
                </td>
            </tr>
        </table>

        <table class="parties">
            <tr>
                <td style="width:40%">
                    <p class="label">Deliver to</p>
                    <div class="deliver pre">{{ $deliver ?: '—' }}@if ($phone){{ "\n" }}Tel: {{ $phone }}@endif</div>
                </td>
                <td style="width:30%">
                    <p class="label">Customer</p>
                    <div class="pre">{{ $order->billing_name ?: '—' }}
{{ $order->email }}</div>
                </td>
                <td style="width:30%">
                    <p class="label">Returns to</p>
                    <div class="pre"><span class="strong">{{ $store['company'] }}</span>
{{ $store['address'] }}</div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
            <tr><th>Item</th><th style="width:22%">SKU</th><th class="center" style="width:10%">Qty</th><th class="center" style="width:10%">Packed</th></tr>
            </thead>
            <tbody>
            @foreach ($order->items as $item)
                @php $remaining = max(0, $item->quantity - $item->refunded_quantity); @endphp
                <tr @if ($remaining === 0) style="color:#9ca3af" @endif>
                    <td>
                        <span class="strong">{{ $item->name }}</span>
                        @foreach ((array) $item->options as $label => $value)
                            <div class="opt">{{ $label }}: {{ is_array($value) ? implode(', ', $value) : $value }}</div>
                        @endforeach
                        @if ($item->refunded_quantity > 0)<div class="refunded">{{ $item->refunded_quantity }} refunded – don’t send</div>@endif
                    </td>
                    <td>{{ $item->sku ?: '—' }}</td>
                    <td class="qty">{{ $remaining }}</td>
                    <td class="center"><span class="check"></span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="opt" style="text-align:right">{{ $toSend }} {{ \Illuminate\Support\Str::plural('item', $toSend) }} in this parcel</p>

        @if ($order->customer_note)
            <div class="note"><span class="strong">Customer note:</span> {{ $order->customer_note }}</div>
        @endif

        <table class="signoff">
            <tr><td>Packed by<div class="line"></div></td><td>Checked by<div class="line"></div></td></tr>
        </table>

        <div class="foot">
            {{ $store['packing_footer'] ?: 'Thank you for your order!'.($store['phone'] || $store['email'] ? ' Questions or returns? Contact us'.($store['phone'] ? ' on '.$store['phone'] : '').($store['email'] ? ' at '.$store['email'] : '').'.' : '') }}<br>
            <strong>{{ $store['company'] }}</strong>@if ($store['website']) · {{ $store['website'] }}@endif
        </div>
    </div>
@endforeach
</body>
</html>
