{{--
    Abandoned-cart reminder (Pine\Commerce\Mail\AbandonedCartReminder), inside the theme's emails.layouts.base.
    Data: $heading, $storeName, $intro, $lines (CartLine), $subtotal, $restoreUrl, $unsubscribeUrl, $coupon, $couponText, $brand
    No tracking pixel: the only thing recorded is a click on the signed basket link.
--}}
@extends('emails.layouts.base')

@php($onBrand = \Pine\Commerce\Theme\Storefront::contrast($brand))

@section('body')
@if ($intro !== '')
    <p style="margin:0 0 24px;">{{ $intro }}</p>
@endif

<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:0 0 16px;border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;">
    @foreach ($lines as $line)
        <tr>
            <td width="88" valign="top" style="padding:12px 0 12px 12px;{{ $loop->last ? '' : 'border-bottom:1px solid #e5e7eb;' }}">
                <img src="{{ $line->imageUrl() }}" alt="{{ $line->imageAlt() }}" width="72" style="display:block;border:0;width:72px;height:auto;border-radius:6px;">
            </td>
            <td valign="middle" style="padding:12px;{{ $loop->last ? '' : 'border-bottom:1px solid #e5e7eb;' }}">
                <p style="margin:0 0 2px;font-weight:600;line-height:1.4;">{{ $line->name() }}</p>
                @if ($line->options())<p style="margin:0 0 2px;color:#6b7280;font-size:13px;">{{ implode(', ', $line->options()) }}</p>@endif
                <p style="margin:0;color:#6b7280;font-size:13px;">Qty {{ $line->quantity }}</p>
            </td>
            <td valign="middle" align="right" style="padding:12px;white-space:nowrap;font-weight:600;{{ $loop->last ? '' : 'border-bottom:1px solid #e5e7eb;' }}">{{ money($line->subtotal()) }}</td>
        </tr>
    @endforeach
</table>
<p style="margin:0 0 24px;text-align:right;">Subtotal: <strong>{{ money($subtotal) }}</strong></p>

@if ($coupon)
    <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:0 0 24px;">
        <tr>
            <td align="center" style="padding:16px;border:2px dashed {{ $brand }};border-radius:8px;">
                <p style="margin:0 0 4px;font-size:18px;font-weight:700;">{{ $couponText }} with code <span style="font-family:monospace;letter-spacing:1px;">{{ $coupon->code }}</span></p>
                <p style="margin:0;color:#6b7280;font-size:13px;">Applied automatically when you return to your basket. Single use{{ $coupon->expires_at ? ', valid until '.$coupon->expires_at->format('j F Y') : '' }}.</p>
            </td>
        </tr>
    </table>
@endif

<p style="margin:0 0 24px;text-align:center;">
    <a href="{{ $restoreUrl }}" style="display:inline-block;background-color:{{ $brand }};color:{{ $onBrand }};text-decoration:none;font-weight:600;padding:14px 28px;border-radius:6px;">Return to my basket</a>
</p>

<p style="margin:0 0 16px;">Prices and stock are checked again when you return. If you have a question, just reply to this email.</p>
<p style="margin:0;color:#8a8f98;font-size:12px;">You’re receiving this because you started a checkout at {{ $storeName }}. <a href="{{ $unsubscribeUrl }}" style="color:#6b7280;">Unsubscribe from basket reminders</a>.</p>
@endsection
