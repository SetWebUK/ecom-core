@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Hi {{ $user->first_name ?: ($user->name ?: 'there') }},</p>
<p style="margin:0 0 16px;">Thanks for creating an account with {{ $storeName }}. Your username is <strong>{{ $user->email }}</strong>.</p>
<p style="margin:0 0 24px;">You can view your orders, change your password and manage your addresses in your account:</p>
<p style="margin:0 0 24px;"><a href="{{ route('account') }}" style="display:inline-block;padding:12px 22px;border-radius:8px;background:{{ \Pine\Commerce\Theme\Storefront::color('primary_color', '#1d4ed8') }};color:{{ \Pine\Commerce\Theme\Storefront::contrast(\Pine\Commerce\Theme\Storefront::color('primary_color', '#1d4ed8')) }};text-decoration:none;font-weight:600;">Go to my account</a></p>
<p style="margin:0;">We look forward to seeing you soon.</p>
@endsection
