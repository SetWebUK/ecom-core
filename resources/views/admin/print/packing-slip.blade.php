{{-- Printable A4 packing slip(s). Route admin.print (document=packing-slip, ?orders=1,2,3). No prices – safe to put in the box. --}}
@php
    use Pine\Commerce\Services\Admin\Countries;
    use Pine\Commerce\Services\Admin\LocalTime;

    $address = function ($order, string $type): string {
        $name = trim($order->{$type.'_first_name'}.' '.$order->{$type.'_last_name'});
        $country = $order->{$type.'_country'};
        $lines = array_filter([$name, $order->{$type.'_company'}, $order->{$type.'_address_1'}, $order->{$type.'_address_2'}, $order->{$type.'_city'}, $order->{$type.'_county'}, $order->{$type.'_postcode'},
            $country && $country !== 'GB' ? Countries::name($country) : null], fn ($l) => trim((string) $l) !== '');

        return implode("\n", $lines);
    };
@endphp
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $orders->count() === 1 ? 'Packing slip #'.$orders->first()->number : $orders->count().' packing slips' }} – {{ $store['name'] }}</title>
    @include('commerce::admin.print._styles')
</head>
<body>
<div class="toolbar">
    <span>{{ $orders->count() === 1 ? 'Packing slip for order #'.$orders->first()->number : $orders->count().' packing slips – one per page' }}</span>
    <div class="toolbar__actions">
        <a href="javascript:window.close()">Close</a>
        <a href="{{ route('admin.pdf', ['document' => 'packing-slip', 'orders' => $orders->pluck('id')->implode(',')]) }}">Download PDF</a>
        <button type="button" onclick="window.print()">Print</button>
    </div>
</div>

@foreach ($orders as $order)
    @php
        $shipping = $address($order, 'shipping');
        $deliver = $shipping !== '' ? $shipping : $address($order, 'billing');
        $phone = $order->shipping_phone ?: $order->phone;
        $toSend = $order->items->sum(fn ($i) => max(0, $i->quantity - $i->refunded_quantity));
    @endphp
    <section class="doc">
        <div class="doc__body">
            <div class="head">
                @if ($store['logo'])<img src="{{ $store['logo'] }}" alt="{{ $store['name'] }}">@else<strong class="store-name">{{ $store['name'] }}</strong>@endif
                <div class="title">
                    <h1>Packing slip</h1>
                    <div class="big-number">#{{ $order->number }}</div>
                    <dl class="facts" style="margin-top:6px">
                        <dt>Order date</dt><dd>{{ LocalTime::format($order->created_at, 'j F Y') }}</dd>
                        <dt>Delivery</dt><dd>{{ $order->shipping_method_title ?: '—' }}</dd>
                        @if ($order->tracking_number)<dt>Tracking</dt><dd>{{ trim($order->tracking_carrier.' '.$order->tracking_number) }}</dd>@endif
                    </dl>
                </div>
            </div>

            <div class="parties" style="grid-template-columns: 1.4fr 1fr 1fr">
                <div>
                    <h3>Deliver to</h3>
                    <p class="deliver">{{ $deliver ?: '—' }}@if ($phone){{ "\n" }}Tel: {{ $phone }}@endif</p>
                </div>
                <div>
                    <h3>Customer</h3>
                    <p>{{ $order->billing_name ?: '—' }}
{{ $order->email }}</p>
                </div>
                <div>
                    <h3>Returns to</h3>
                    <p><span class="strong">{{ $store['company'] }}</span>
{{ $store['address'] }}</p>
                </div>
            </div>

            <table class="items">
                <thead>
                <tr><th style="width:56px"></th><th>Item</th><th style="width:130px">SKU</th><th class="num" style="width:56px;text-align:center">Qty</th><th style="width:60px;text-align:center">Packed</th></tr>
                </thead>
                <tbody>
                @foreach ($order->items as $item)
                    @php
                        $remaining = max(0, $item->quantity - $item->refunded_quantity);
                        $image = $item->variation?->image ?: $item->product?->images->first()?->path;
                    @endphp
                    <tr @if ($remaining === 0) style="opacity:.55" @endif>
                        <td>@if ($image)<img class="thumb" src="{{ media_url($image) }}" alt="">@endif</td>
                        <td>
                            <strong>{{ $item->name }}</strong>
                            @foreach ((array) $item->options as $label => $value)
                                <div class="opt">{{ $label }}: {{ is_array($value) ? implode(', ', $value) : $value }}</div>
                            @endforeach
                            @if ($item->refunded_quantity > 0)<div class="refunded">{{ $item->refunded_quantity }} refunded – don’t send</div>@endif
                        </td>
                        <td>{{ $item->sku ?: '—' }}</td>
                        <td class="qty">{{ $remaining }}</td>
                        <td style="text-align:center"><span class="check"></span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="opt" style="text-align:right;margin-top:8px">{{ $toSend }} {{ \Illuminate\Support\Str::plural('item', $toSend) }} in this parcel</p>

            @if ($order->customer_note)
                <div class="note"><strong>Customer note:</strong> {{ $order->customer_note }}</div>
            @endif

            <div class="signoff">
                <div>Packed by<span></span></div>
                <div>Checked by<span></span></div>
            </div>
        </div>

        <div class="foot">
            {{ $store['packing_footer'] ?: 'Thank you for your order! Questions or returns? Call '.$store['phone'].' or email '.$store['email'].'.' }}<br>
            <strong>{{ $store['company'] }}</strong>@if ($store['website']) · {{ $store['website'] }}@endif
        </div>
    </section>
@endforeach
</body>
</html>
