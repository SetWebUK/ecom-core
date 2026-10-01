@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Hi {{ $user->first_name ?: ($user->name ?: 'there') }},</p>
<p style="margin:0 0 16px;">Someone asked to reset the password for your {{ $storeName }} account ({{ $user->email }}).</p>
<p style="margin:0 0 24px;"><a href="{{ $url }}" style="display:inline-block;padding:12px 22px;border-radius:8px;background:{{ \Pine\Commerce\Theme\Storefront::color('primary_color', '#1d4ed8') }};color:{{ \Pine\Commerce\Theme\Storefront::contrast(\Pine\Commerce\Theme\Storefront::color('primary_color', '#1d4ed8')) }};text-decoration:none;font-weight:600;">Choose a new password</a></p>
<p style="margin:0 0 16px;color:#6b7280;">This link expires in {{ $minutes ?? 60 }} minutes. If you didn’t ask for this, you can ignore this email – your password won’t change.</p>
@endsection
