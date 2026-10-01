{{--
    Invoice PDF (dompdf). One page per order in $orders. Rendered by Pine\Commerce\Services\Invoices\InvoicePdf.
    Override in a theme (views/pdf/invoice.blade.php) or the app (resources/views/pdf/invoice.blade.php).
    Data: $orders, $store (DocumentData::store()), $logo (data: URI or null), $document.
--}}
@php
    use Pine\Commerce\Services\Admin\LocalTime;
    use Pine\Commerce\Services\Admin\PaymentMethods;
    use Pine\Commerce\Services\Invoices\DocumentData;
    use Pine\Commerce\Services\Invoices\Invoices;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', config('commerce.store.locale', 'en_GB')) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $orders->count() === 1 ? 'Invoice '.(Invoices::number($orders->first(), false) ?? $orders->first()->number) : $orders->count().' invoices' }} – {{ $store['name'] }}</title>
    @include('commerce::pdf._styles')
</head>
<body>
@foreach ($orders as $order)
    @php
        $number = Invoices::number($order, false);
        $date = Invoices::date($order);
        $billing = DocumentData::address($order, 'billing');
        $shipping = DocumentData::address($order, 'shipping');
        $stamp = DocumentData::stamp($order);
        $taxLines = DocumentData::taxLines($order);
        $taxTotal = (float) $order->tax_total;
        $refunded = (float) $order->refunded_total;
        $taxIncluded = DocumentData::taxIncluded($order); // prices included tax: shown as "Includes VAT".
        $showBreakdown = count($taxLines) > 1 || collect($taxLines)->contains(fn ($l) => $l['rate'] !== null || $l['net'] !== null);
        // orders store amounts without tax: shown with their tax when the prices included it
        $amounts = method_exists($order, 'displayAmounts') ? $order->displayAmounts($taxIncluded) : null;
    @endphp
    <div class="doc{{ $loop->last ? ' doc--last' : '' }}">
        <table class="head">
            <tr>
                <td>
                    @if ($logo)<img class="logo" src="{{ $logo }}" alt="{{ $store['name'] }}">@else<span class="store-name">{{ $store['name'] }}</span>@endif
                </td>
                <td style="text-align:right">
                    <h1>{{ $number !== null ? 'Invoice' : 'Pro forma invoice' }}</h1>
                    <table class="facts">
                        @if ($number !== null)<tr><td class="k">Invoice no.</td><td class="v">{{ $number }}</td></tr>@endif
                        @if ($number !== null && $date)<tr><td class="k">Invoice date</td><td class="v">{{ LocalTime::format($date, 'j F Y') }}</td></tr>@endif
                        <tr><td class="k">Order no.</td><td class="v">{{ $order->number }}</td></tr>
                        <tr><td class="k">Order date</td><td class="v">{{ LocalTime::format($order->created_at, 'j F Y') }}</td></tr>
                        @if ($order->payment_method || $order->payment_method_title)
                            <tr><td class="k">Payment</td><td class="v">{{ PaymentMethods::label($order->payment_method, $order->payment_method_title) }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        <table class="parties">
            <tr>
                <td>
                    <p class="label">From</p>
                    <div class="pre"><span class="strong">{{ $store['company'] }}</span>
{{ $store['address'] }}@if ($store['phone']){{ "\n" }}{{ $store['phone'] }}@endif @if ($store['email']){{ "\n" }}{{ $store['email'] }}@endif @if ($store['vat_number']){{ "\n" }}{{ setting('tax.label', 'VAT') }} no. {{ $store['vat_number'] }}@endif @if ($store['company_number']){{ "\n" }}Company no. {{ $store['company_number'] }}@endif</div>
                </td>
                <td>
                    <p class="label">Bill to</p>
                    <div class="pre">@if ($billing !== ''){{ $billing }}{{ "\n" }}@endif{{ $order->email }}@if ($order->phone){{ "\n" }}{{ $order->phone }}@endif</div>
                </td>
                <td>
                    <p class="label">Ship to</p>
                    <div class="pre">{{ $shipping !== '' ? $shipping : ($billing !== '' ? $billing : '—') }}@if ($order->shipping_method_title){{ "\n" }}<span class="opt">{{ $order->shipping_method_title }}</span>@endif</div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
            <tr><th>Item</th><th class="num" style="width:12%">Qty</th><th class="num" style="width:18%">Unit price</th><th class="num" style="width:18%">Amount</th></tr>
            </thead>
            <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>
                        <span class="strong">{{ $item->name }}</span>
                        @if ($item->sku)<div class="opt">SKU: {{ $item->sku }}</div>@endif
                        @foreach ((array) $item->options as $label => $value)
                            <div class="opt">{{ $label }}: {{ is_array($value) ? implode(', ', $value) : $value }}</div>
                        @endforeach
                        @if ($item->refunded_quantity > 0)<div class="refunded">{{ $item->refunded_quantity }} refunded</div>@endif
                    </td>
                    <td class="num">{{ $item->quantity }}</td>
                    {{-- inclusive prices: the unit price actually charged (VAT outside the shop's country is taken off the catalogue price) --}}
                    <td class="num">{{ money($amounts && method_exists($order, 'pricesIncludeTax') && $order->pricesIncludeTax() && (int) $item->quantity > 0 ? $item->displaySubtotal($taxIncluded) / (int) $item->quantity : $item->unit_price) }}</td>
                    <td class="num">{{ money($amounts ? $item->displaySubtotal($taxIncluded) : $item->subtotal) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <table class="summary">
            <tr>
                <td style="width:52%; padding-right:18px">
                    <span class="stamp stamp--{{ $stamp[1] }}">{{ $stamp[0] }}</span>
                    @if ($order->transaction_id)<div class="opt" style="margin-top:5px">Payment reference: {{ $order->transaction_id }}</div>@endif
                    @if ($stamp[1] === 'due')<div class="opt" style="margin-top:5px">Please quote order number {{ $order->number }} with your payment.</div>@endif

                    @if ($showBreakdown)
                        <table class="taxes">
                            <thead><tr><th>{{ setting('tax.label', 'VAT') }} rate</th><th class="num">Net</th><th class="num">{{ setting('tax.label', 'VAT') }}</th></tr></thead>
                            <tbody>
                            @foreach ($taxLines as $line)
                                <tr>
                                    <td>{{ DocumentData::taxLabel($line) }}</td>
                                    <td class="num">{{ $line['net'] !== null ? money($line['net']) : '—' }}</td>
                                    <td class="num">{{ money($line['tax']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </td>
                <td>
                    <table class="totals">
                        <tr><td>Subtotal</td><td class="num">{{ money($amounts['subtotal'] ?? $order->subtotal) }}</td></tr>
                        @if ((float) ($amounts['discount'] ?? $order->discount_total) > 0)
                            <tr><td>Discount{{ $order->coupon_code ? ' ('.str_replace(',', ', ', $order->coupon_code).')' : '' }}</td><td class="num">−{{ money($amounts['discount'] ?? $order->discount_total) }}</td></tr>
                        @endif
                        <tr><td>Shipping</td><td class="num">{{ (float) ($amounts['shipping'] ?? $order->shipping_total) > 0 ? money($amounts['shipping'] ?? $order->shipping_total) : 'Free' }}</td></tr>
                        @if ($taxTotal > 0 && ! $taxIncluded)
                            @if (count($taxLines) > 1)
                                @foreach ($taxLines as $line)
                                    <tr><td>{{ DocumentData::taxLabel($line) }}</td><td class="num">{{ money($line['tax']) }}</td></tr>
                                @endforeach
                            @else
                                <tr><td>{{ setting('tax.label', 'VAT') }}</td><td class="num">{{ money($taxTotal) }}</td></tr>
                            @endif
                        @endif
                        <tr class="grand"><td>Total</td><td class="num">{{ money($order->total) }}</td></tr>
                        @if ($taxIncluded)
                            <tr class="muted"><td>Includes {{ setting('tax.label', 'VAT') }}</td><td class="num">{{ money($taxTotal) }}</td></tr>
                        @endif
                        @if ($refunded > 0)
                            <tr class="muted"><td>Refunded</td><td class="num">−{{ money($refunded) }}</td></tr>
                            <tr class="grand"><td>Net total</td><td class="num">{{ money((float) $order->total - $refunded) }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        @if ($order->customer_note)
            <div class="note"><span class="strong">Customer note:</span> {{ $order->customer_note }}</div>
        @endif
        @if ($store['invoice_notes'])
            <div class="note">{{ $store['invoice_notes'] }}</div>
        @endif

        <div class="foot">
            {{ $store['invoice_footer'] ?: 'Thank you for shopping with '.$store['name'].'. Please keep this invoice for your records.' }}<br>
            <strong>{{ $store['company'] }}</strong>
            @if ($store['company_number']) · Company no. {{ $store['company_number'] }}@endif
            @if ($store['vat_number']) · {{ setting('tax.label', 'VAT') }} no. {{ $store['vat_number'] }}@endif
            @if ($store['registered_office'])<br>Registered office: {{ $store['registered_office'] }}@endif
            @if ($store['website']) · {{ $store['website'] }}@endif
        </div>
    </div>
@endforeach
</body>
</html>
