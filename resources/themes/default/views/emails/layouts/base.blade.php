{{--
    Transactional email wrapper (inline styles only). Expects $heading, $storeName; content in @section('body').
    Brand colour = the theme's primary colour (Admin › Settings › Theme).
--}}
@php
    $brand = \Pine\Commerce\Theme\Storefront::color('primary_color', '#1d4ed8');
    $onBrand = \Pine\Commerce\Theme\Storefront::contrast($brand);
    $logo = setting('store.logo');
    $logoUrl = $logo ? media_url($logo) : null;
    $storeName = $storeName ?? setting('store.name', config('app.name'));
    $footer = setting('emails.footer_text') ?: ($storeName.' · '.preg_replace('#^https?://#', '', url('/')));
    $font = "font-family:-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;";
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading ?? $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;-webkit-text-size-adjust:none;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="background-color:#f4f5f7;">
    <tr>
        <td align="center" valign="top" style="padding:32px 12px;">
            <table width="600" cellpadding="0" cellspacing="0" border="0" role="presentation" style="max-width:600px;width:100%;">
                <tr>
                    <td align="center" style="padding:0 0 20px;">
                        <a href="{{ url('/') }}" style="text-decoration:none;color:#0f172a;{{ $font }}font-size:20px;font-weight:700;">
                            @if ($logoUrl)<img src="{{ $logoUrl }}" alt="{{ $storeName }}" width="180" style="display:block;border:0;max-width:180px;height:auto;">@else{{ $storeName }}@endif
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="background-color:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
                            <tr><td style="background-color:{{ $brand }};padding:28px 40px;"><h1 style="margin:0;color:{{ $onBrand }};{{ $font }}font-size:24px;font-weight:700;line-height:1.25;">{{ $heading ?? $storeName }}</h1></td></tr>
                            <tr><td style="padding:32px 40px;color:#1f2937;{{ $font }}font-size:15px;line-height:1.6;">@yield('body')</td></tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:20px 12px 0;color:#6b7280;{{ $font }}font-size:12px;line-height:1.6;">
                        {{ $footer }}<br>
                        @if (setting('store.phone')){{ setting('store.phone') }} · @endif
                        <a href="mailto:{{ setting('store.email', config('mail.from.address')) }}" style="color:{{ $brand }};text-decoration:none;">{{ setting('store.email', config('mail.from.address')) }}</a>
                        @if (setting('store.legal_line'))<br>{{ setting('store.legal_line') }}@endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
