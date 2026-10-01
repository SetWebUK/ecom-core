@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Order #{{ $order->number }} from <strong>{{ $order->billing_name }}</strong> ({{ $order->email }}) has been cancelled.</p>
@include('emails.partials.order-details', ['admin' => true])
@include('emails.partials.addresses')
@endsection
