@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Hi {{ $order->billing_first_name ?: 'there' }},</p>
<p style="margin:0 0 16px;">Good news – your order is on its way.</p>
@if ($order->tracking_number)
    <p style="margin:0 0 16px;">{{ $order->tracking_carrier ?: 'Tracking number' }}: @if (! empty($trackingUrl))<a href="{{ $trackingUrl }}" style="font-weight:700;">{{ $order->tracking_number }}</a>@else<strong>{{ $order->tracking_number }}</strong>@endif</p>
@endif
@include('emails.partials.order-details')
@include('emails.partials.addresses')
<p style="margin:0;">Thanks for shopping with us.</p>
@endsection
