@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Payment for order #{{ $order->number }} from <strong>{{ $order->billing_name }}</strong> ({{ $order->email }}) has failed.</p>
@include('emails.partials.order-details', ['admin' => true])
@include('emails.partials.addresses')
@endsection
