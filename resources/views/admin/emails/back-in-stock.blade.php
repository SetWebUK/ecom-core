{{--
    Back-in-stock email (Pine\Commerce\Mail\BackInStock). Same branded wrapper as the order emails (emails.layouts.base).
    Data: $heading, $storeName, $product, $variantLabel, $price, $imageUrl, $productUrl
--}}
@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Hi there,</p>
<p style="margin:0 0 24px;">Good news – you asked us to let you know when this was available again, and it’s back in stock now:</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:0 0 24px;border:1px solid #e5e7eb;border-radius:8px;">
    <tr>
        @if ($imageUrl)
            <td width="120" valign="top" style="padding:16px;">
                <a href="{{ $productUrl }}" style="text-decoration:none;"><img src="{{ $imageUrl }}" alt="{{ $product?->name }}" width="104" style="display:block;border:0;width:104px;height:auto;border-radius:6px;"></a>
            </td>
        @endif
        <td valign="middle" style="padding:16px 16px 16px {{ $imageUrl ? '0' : '16px' }};">
            <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#1b254f;line-height:1.4;">{{ $product?->name }}</p>
            @if ($variantLabel)<p style="margin:0 0 4px;color:#6b7280;">{{ $variantLabel }}</p>@endif
            @if ($price !== null)<p style="margin:0;font-size:16px;font-weight:600;color:#3c3c3c;">{{ money($price) }}</p>@endif
        </td>
    </tr>
</table>

@if ($productUrl)
    <p style="margin:0 0 24px;">
        <a href="{{ $productUrl }}" style="display:inline-block;background-color:#FF6700;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:6px;">View product</a>
    </p>
@endif

<p style="margin:0 0 16px;">Stock is limited and we can’t hold items, so if you still want it we’d recommend ordering soon.</p>
<p style="margin:0;color:#8a8f98;font-size:12px;">You’re receiving this one-off email because you asked to be told when this item was back in stock. We won’t email you about it again.</p>
@endsection
