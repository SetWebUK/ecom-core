{{-- Billing / delivery addresses. $order --}}
@php
    $font = "font-family:-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;";
    $box = 'margin:0;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;font-style:normal;line-height:1.6;font-size:14px;color:#1f2937;'.$font;
    $countries = \Pine\Commerce\Services\Checkout\CheckoutService::countries();
    $lines = function (string $type) use ($order, $countries) {
        $l = $type === 'billing' ? $order->billingAddressLines() : $order->shippingAddressLines();
        $country = $order->{$type.'_country'};

        return array_map(fn ($v) => $v === $country ? ($countries[$v] ?? $v) : $v, $l);
    };
@endphp
<table cellspacing="0" cellpadding="0" border="0" width="100%" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td valign="top" width="50%" style="padding:0 8px 0 0;">
            <h3 style="{{ $font }}font-size:15px;margin:0 0 8px;color:#0f172a;">Billing address</h3>
            <address style="{{ $box }}">{!! implode('<br>', array_map('e', $lines('billing'))) !!}@if ($order->phone)<br>{{ $order->phone }}@endif @if ($order->email)<br>{{ $order->email }}@endif</address>
        </td>
        @if ($order->shipping_address_1)
            <td valign="top" width="50%" style="padding:0 0 0 8px;">
                <h3 style="{{ $font }}font-size:15px;margin:0 0 8px;color:#0f172a;">Delivery address</h3>
                <address style="{{ $box }}">{!! implode('<br>', array_map('e', $lines('shipping'))) !!}@if ($order->shipping_phone)<br>{{ $order->shipping_phone }}@endif</address>
            </td>
        @endif
    </tr>
</table>
