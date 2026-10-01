{{--
    Daily low-stock email to the shop (Pine\Commerce\Mail\LowStockReport). Same branded wrapper as the order emails.
    Data: $heading, $storeName, $low, $out (Product collections), $limit, $productUrl (callable), $settingsUrl
--}}
@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Here are the products that need restocking today.</p>

@foreach ([['Running low', $low, true], ['Sold out', $out, false]] as [$title, $products, $showQty])
    @if ($products->isNotEmpty())
        <p style="margin:24px 0 8px;font-size:16px;font-weight:600;">{{ $title }} ({{ $products->count() }}{{ $products->count() >= $limit ? '+' : '' }})</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse:collapse;">
            @foreach ($products as $product)
                @php($link = $productUrl($product))
                <tr>
                    <td style="padding:8px 0;border-bottom:1px solid #e5e7eb;">
                        @if ($link)<a href="{{ $link }}" style="color:inherit;">{{ $product->name }}</a>@else{{ $product->name }}@endif
                        @if ($product->sku)<span style="color:#6b7280;font-size:13px;"> · SKU {{ $product->sku }}</span>@endif
                    </td>
                    <td align="right" style="padding:8px 0;border-bottom:1px solid #e5e7eb;white-space:nowrap;font-weight:600;">
                        {{ $showQty ? $product->stock_quantity.' left' : 'Sold out' }}
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
@endforeach

<p style="margin:24px 0 0;color:#8a8f98;font-size:12px;">
    You get this email once a day while something is low or sold out.
    @if ($settingsUrl)<a href="{{ $settingsUrl }}" style="color:#6b7280;">Switch it off in Settings › Scheduled tasks</a>.@endif
</p>
@endsection
