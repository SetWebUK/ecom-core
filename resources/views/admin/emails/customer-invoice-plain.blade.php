{{-- Fallback for Pine\Commerce\Mail\Admin\CustomerInvoice when the storefront email layout is missing. --}}
<!DOCTYPE html>
<html lang="en-GB">
<body style="margin:0;padding:0;background:#f5f6f8;font-family:Arial,sans-serif;color:#3c3c3c">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;padding:32px 12px">
    <tr><td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px">
            <tr><td style="background:#1b254f;border-radius:8px 8px 0 0;padding:28px 40px;color:#ffffff;font-size:24px">{{ $heading }}</td></tr>
            <tr><td style="padding:32px 40px;font-size:14px;line-height:1.6">
                <p style="margin:0 0 16px">Hi {{ $order->billing_first_name ?: 'there' }},</p>
                @if ($payUrl)
                    <p style="margin:0 0 16px">An order has been created for you on {{ $storeName }}. Pay securely when you’re ready:</p>
                    <p style="margin:0 0 24px"><a href="{{ $payUrl }}" style="display:inline-block;background:#FF6700;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:4px">Pay for this order</a></p>
                @endif
                <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e5e5">
                    @foreach ($order->items as $item)
                        <tr><td style="border:1px solid #e5e5e5">{{ $item->name }}@if ($item->sku) <small>(#{{ $item->sku }})</small>@endif</td><td style="border:1px solid #e5e5e5">×{{ $item->quantity }}</td><td style="border:1px solid #e5e5e5;text-align:right">{{ money($item->subtotal) }}</td></tr>
                    @endforeach
                    @if ((float) $order->discount_total > 0)<tr><td colspan="2" style="border:1px solid #e5e5e5">Discount</td><td style="border:1px solid #e5e5e5;text-align:right">−{{ money($order->discount_total) }}</td></tr>@endif
                    <tr><td colspan="2" style="border:1px solid #e5e5e5">Shipping</td><td style="border:1px solid #e5e5e5;text-align:right">{{ (float) $order->shipping_total > 0 ? money($order->shipping_total) : 'Free' }}</td></tr>
                    <tr><td colspan="2" style="border:1px solid #e5e5e5"><strong>Total</strong></td><td style="border:1px solid #e5e5e5;text-align:right"><strong>{{ money($order->total) }}</strong></td></tr>
                </table>
                <p style="margin:24px 0 0;color:#8a8f98;font-size:12px">{{ $storeName }} · {{ setting('store.phone') }} · {{ setting('store.email') }}</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
