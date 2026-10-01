{{-- Order items + totals. $order, optional $admin (link to the back office) --}}
@php
    $font = "font-family:-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;";
    $cell = 'padding:10px 0;border-bottom:1px solid #e5e7eb;vertical-align:top;'.$font.'font-size:14px;color:#1f2937;';
    $admin = $admin ?? false;
    $amounts = $order->displayAmounts();
@endphp
<h2 style="{{ $font }}font-size:17px;margin:24px 0 8px;color:#0f172a;">
    @if ($admin && \Illuminate\Support\Facades\Route::has('admin.orders.show'))<a href="{{ route('admin.orders.show', $order) }}" style="color:#0f172a;">Order #{{ $order->number }}</a>@else Order #{{ $order->number }}@endif
    <span style="font-weight:400;color:#6b7280;font-size:14px;">· {{ \Pine\Commerce\Services\Checkout\UkTime::format($order->created_at, 'j F Y') }}</span>
</h2>
<table cellspacing="0" cellpadding="0" border="0" width="100%" role="presentation" style="border-collapse:collapse;margin:0 0 24px;">
    @foreach ($order->items as $item)
        <tr>
            <td style="{{ $cell }}">
                {{ $item->name }} <span style="color:#6b7280;">× {{ $item->quantity }}</span>
                @if (is_array($item->options) && $item->options)<br><span style="color:#6b7280;font-size:13px;">@foreach ($item->options as $k => $v){{ $k }}: {{ is_array($v) ? implode(', ', $v) : $v }}@if (! $loop->last) · @endif @endforeach</span>@endif
                @if ($admin && $item->sku)<br><span style="color:#6b7280;font-size:12px;">SKU {{ $item->sku }}</span>@endif
            </td>
            <td style="{{ $cell }}text-align:right;white-space:nowrap;">{{ money($item->displaySubtotal($amounts['incl'])) }}</td>
        </tr>
    @endforeach
    <tr><td style="{{ $cell }}color:#6b7280;">Subtotal</td><td style="{{ $cell }}text-align:right;">{{ money($amounts['subtotal']) }}</td></tr>
    @if ($amounts['discount'] > 0)<tr><td style="{{ $cell }}color:#6b7280;">Discount @if ($order->coupon_code)({{ $order->coupon_code }})@endif</td><td style="{{ $cell }}text-align:right;">−{{ money($amounts['discount']) }}</td></tr>@endif
    <tr><td style="{{ $cell }}color:#6b7280;">Delivery @if ($order->shipping_method_title)<span style="font-size:13px;">({{ $order->shipping_method_title }})</span>@endif</td><td style="{{ $cell }}text-align:right;">{{ $amounts['shipping'] > 0 ? money($amounts['shipping']) : 'Free' }}</td></tr>
    @foreach ($amounts['tax_lines'] as $taxLine)<tr><td style="{{ $cell }}color:#6b7280;">{{ $amounts['incl'] ? 'Includes '.$taxLine['label'] : $taxLine['label'] }}</td><td style="{{ $cell }}text-align:right;">{{ money($taxLine['amount']) }}</td></tr>@endforeach
    @if ($order->payment_method_title)<tr><td style="{{ $cell }}color:#6b7280;">Payment method</td><td style="{{ $cell }}text-align:right;">{{ $order->payment_method_title }}</td></tr>@endif
    <tr><td style="{{ $cell }}font-weight:700;font-size:16px;border-bottom:0;">Total</td><td style="{{ $cell }}text-align:right;font-weight:700;font-size:16px;border-bottom:0;">{{ money($order->total) }}</td></tr>
    @if ((float) $order->refunded_total > 0)<tr><td style="{{ $cell }}color:#6b7280;border-bottom:0;">Refunded</td><td style="{{ $cell }}text-align:right;border-bottom:0;">−{{ money($order->refunded_total) }}</td></tr>@endif
</table>
@if ($order->customer_note)
    <p style="margin:0 0 24px;padding:12px 16px;background:#f4f5f7;border-radius:8px;"><strong>Note:</strong> {!! nl2br(e($order->customer_note)) !!}</p>
@endif
