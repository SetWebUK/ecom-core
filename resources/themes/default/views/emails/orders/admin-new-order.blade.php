@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">You’ve received a new order from <strong>{{ $order->billing_name }}</strong> ({{ $order->email }}).</p>
@if ($order->source_type || $order->source)
    <p style="margin:0 0 16px;color:#6b7280;">Source: {{ trim(($order->source_type ?? '').' '.($order->source ?? '')) }}</p>
@endif
@include('emails.partials.order-details', ['admin' => true])
@include('emails.partials.addresses')
@endsection
