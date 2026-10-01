@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Hi {{ $order->billing_first_name ?: 'there' }},</p>
<p style="margin:0 0 16px;">Thanks for your order – we’ve received it and we’re getting it ready. Here are the details of order #{{ $order->number }}:</p>
@include('emails.partials.order-details')
@include('emails.partials.addresses')
<p style="margin:0;">Thanks for shopping with {{ $storeName }}.</p>
@endsection
