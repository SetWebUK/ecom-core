{{-- Summary of a placed order (order received, order pay, order tracking). $order --}}
@php $amounts = $order->displayAmounts(); @endphp
<div class="summary">
    <ul class="summary__lines">
        @foreach ($order->items as $item)
            @php $image = $item->variation?->image ?: $item->product?->images?->first()?->path; @endphp
            <li class="summary-line">
                <span class="summary-line__media"><img src="{{ media_url($image) }}" alt="" width="56" height="56" loading="lazy"><span class="summary-line__qty">{{ $item->quantity }}</span></span>
                <span class="summary-line__body">
                    <span class="summary-line__name">{{ $item->name }}</span>
                    @if (is_array($item->options) && $item->options)
                        <span class="summary-line__options">@foreach ($item->options as $label => $value){{ $label }}: {{ is_array($value) ? implode(', ', $value) : $value }}@if (! $loop->last) · @endif @endforeach</span>
                    @endif
                    @if ((int) $item->refunded_quantity > 0)<span class="summary-line__discount">{{ $item->refunded_quantity }} refunded</span>@endif
                </span>
                <span class="summary-line__price">{{ money($item->displaySubtotal($amounts['incl'])) }}</span>
            </li>
        @endforeach
    </ul>
    <dl class="totals">
        <div><dt>Subtotal</dt><dd>{{ money($amounts['subtotal']) }}</dd></div>
        @if ($amounts['discount'] > 0)
            <div class="totals__discount"><dt>Discount @if ($order->coupon_code)({{ $order->coupon_code }})@endif</dt><dd>−{{ money($amounts['discount']) }}</dd></div>
        @endif
        <div><dt>Delivery</dt><dd>{{ $amounts['shipping'] > 0 ? money($amounts['shipping']) : 'Free' }}</dd></div>
        @unless ($amounts['incl'])
            @foreach ($amounts['tax_lines'] as $taxLine)
                <div class="totals__tax"><dt>{{ $taxLine['label'] }}</dt><dd>{{ money($taxLine['amount']) }}</dd></div>
            @endforeach
        @endunless
        <div class="totals__grand"><dt>Total</dt><dd>{{ money($order->total) }}</dd></div>
        @if ($amounts['incl'])
            <div class="totals__includes"><dt>Includes</dt><dd>@foreach ($amounts['tax_lines'] as $taxLine){{ money($taxLine['amount']) }} {{ $taxLine['label'] }}@if (! $loop->last), @endif @endforeach</dd></div>
        @endif
        @if ((float) $order->refunded_total > 0)
            <div class="totals__discount"><dt>Refunded</dt><dd>−{{ money($order->refunded_total) }}</dd></div>
        @endif
    </dl>
</div>
