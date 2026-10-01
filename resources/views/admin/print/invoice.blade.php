{{-- Printable A4 invoice(s). Route admin.print (document=invoice, ?orders=1,2,3). $orders, $store from PrintController. --}}
@php
    use Pine\Commerce\Services\Admin\Countries;
    use Pine\Commerce\Services\Admin\LocalTime;
    use Pine\Commerce\Services\Admin\PaymentMethods;

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
    <title>{{ $orders->count() === 1 ? 'Invoice '.$orders->first()->number : $orders->count().' invoices' }} – {{ $store['name'] }}</title>
    @include('commerce::admin.print._styles')
</head>
<body>
<div class="toolbar">
    <span>{{ $orders->count() === 1 ? 'Invoice for order #'.$orders->first()->number : $orders->count().' invoices – one per page' }}</span>
    <div class="toolbar__actions">
        <a href="javascript:window.close()">Close</a>
        <a href="{{ route('admin.pdf', ['document' => 'invoice', 'orders' => $orders->pluck('id')->implode(',')]) }}">Download PDF</a>
        <button type="button" onclick="window.print()">Print</button>
    </div>
</div>

@foreach ($orders as $order)
    @php
        $billing = $address($order, 'billing');
        $shipping = $address($order, 'shipping');
        $refunded = (float) $order->refunded_total;
        $paid = in_array($order->status, ['processing', 'completed', 'refunded'], true) || ($order->paid_at && $order->status === 'on-hold');
        $stamp = match (true) {
            $order->status === 'refunded' || ($refunded > 0 && $refunded >= (float) $order->total) => ['Refunded', 'refunded'],
            $order->status === 'cancelled' => ['Cancelled', 'refunded'],
            $paid => ['Paid', 'paid'],
            default => ['Payment due', 'due'],
        };
    @endphp
    <section class="doc">
        <div class="doc__body">
            <div class="head">
                @if ($store['logo'])<img src="{{ $store['logo'] }}" alt="{{ $store['name'] }}">@else<strong class="store-name">{{ $store['name'] }}</strong>@endif
                <div class="title">
                    <h1>Invoice</h1>
                    <dl class="facts">
                        <dt>Invoice no.</dt><dd>{{ \Pine\Commerce\Services\Invoices\Invoices::number($order, false) ?? 'Not issued yet' }}</dd>
                        <dt>Invoice date</dt><dd>{{ LocalTime::format(\Pine\Commerce\Services\Invoices\Invoices::date($order), 'j F Y') }}</dd>
                        @if (\Pine\Commerce\Services\Invoices\Invoices::numbering())<dt>Order no.</dt><dd>{{ $order->number }}</dd>@endif
                        <dt>Order date</dt><dd>{{ LocalTime::format($order->created_at, 'j F Y') }}</dd>
                        <dt>Payment</dt><dd>{{ PaymentMethods::label($order->payment_method, $order->payment_method_title) }}</dd>
                    </dl>
                </div>
            </div>

            <div class="parties">
                <div>
                    <h3>From</h3>
                    <p><span class="strong">{{ $store['company'] }}</span>
{{ $store['address'] }}@if ($store['phone']){{ "\n" }}{{ $store['phone'] }}@endif @if ($store['email']){{ "\n" }}{{ $store['email'] }}@endif @if ($store['vat_number']){{ "\n" }}VAT no. {{ $store['vat_number'] }}@endif</p>
                </div>
                <div>
                    <h3>Bill to</h3>
                    <p>@if ($billing !== ''){{ $billing }}{{ "\n" }}@endif{{ $order->email }}@if ($order->phone){{ "\n" }}{{ $order->phone }}@endif</p>
                </div>
                <div>
                    <h3>Ship to</h3>
                    <p>{{ $shipping !== '' ? $shipping : ($billing !== '' ? $billing : '—') }}@if ($order->shipping_method_title){{ "\n" }}<span class="opt">{{ $order->shipping_method_title }}</span>@endif</p>
                </div>
            </div>

            <table class="items">
                <thead>
                <tr><th>Item</th><th class="num" style="width:60px">Qty</th><th class="num" style="width:100px">Unit price</th><th class="num" style="width:100px">Amount</th></tr>
                </thead>
                <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>
                            <strong>{{ $item->name }}</strong>
                            @if ($item->sku)<div class="opt">SKU: {{ $item->sku }}</div>@endif
                            @foreach ((array) $item->options as $label => $value)
                                <div class="opt">{{ $label }}: {{ is_array($value) ? implode(', ', $value) : $value }}</div>
                            @endforeach
                            @if ($item->refunded_quantity > 0)<div class="refunded">{{ $item->refunded_quantity }} refunded</div>@endif
                        </td>
                        <td class="num">{{ $item->quantity }}</td>
                        <td class="num">{{ money($item->unit_price) }}</td>
                        <td class="num">{{ money($item->subtotal) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="summary">
                <div class="summary__notes">
                    <span class="stamp stamp--{{ $stamp[1] }}">{{ $stamp[0] }}</span>
                    @if ($order->transaction_id)<div class="opt" style="margin-top:6px">Payment reference: {{ $order->transaction_id }}</div>@endif
                    @if ($stamp[1] === 'due')
                        <div class="opt" style="margin-top:6px">Please quote order number {{ $order->number }} with your payment.</div>
                    @endif
                </div>
                <table class="totals">
                    <tr><td>Subtotal</td><td class="num">{{ money($order->subtotal) }}</td></tr>
                    @if ((float) $order->discount_total > 0)
                        <tr><td>Discount{{ $order->coupon_code ? ' ('.str_replace(',', ', ', $order->coupon_code).')' : '' }}</td><td class="num">−{{ money($order->discount_total) }}</td></tr>
                    @endif
                    <tr><td>Shipping</td><td class="num">{{ (float) $order->shipping_total > 0 ? money($order->shipping_total) : 'Free' }}</td></tr>
                    @if ((float) $order->tax_total > 0)
                        @foreach ($order->taxBreakdown() as $taxLine)
                            <tr><td>{{ $taxLine['label'] }}</td><td class="num">{{ money($taxLine['amount']) }}</td></tr>
                        @endforeach
                    @endif
                    <tr class="grand"><td>Total</td><td class="num">{{ money($order->total) }}</td></tr>
                    @if ($refunded > 0)
                        <tr class="muted"><td>Refunded</td><td class="num">−{{ money($refunded) }}</td></tr>
                        <tr class="grand"><td>Net total</td><td class="num">{{ money((float) $order->total - $refunded) }}</td></tr>
                    @endif
                </table>
            </div>

            @if ($order->customer_note)
                <div class="note"><strong>Customer note:</strong> {{ $order->customer_note }}</div>
            @endif
            @if (! empty($store['invoice_notes']))
                <div class="note">{{ $store['invoice_notes'] }}</div>
            @endif
        </div>

        <div class="foot">
            {{ $store['invoice_footer'] ?: 'Thank you for shopping with '.$store['name'].'. Please keep this invoice as proof of purchase for your warranty.' }}<br>
            <strong>{{ $store['company'] }}</strong>
            @if ($store['company_number']) · Registered in England &amp; Wales no. {{ $store['company_number'] }}@endif
            @if ($store['vat_number']) · VAT no. {{ $store['vat_number'] }}@endif
            @if ($store['registered_office'])<br>Registered office: {{ $store['registered_office'] }}@endif
            @if ($store['website']) · {{ $store['website'] }}@endif
        </div>
    </section>
@endforeach
</body>
</html>
