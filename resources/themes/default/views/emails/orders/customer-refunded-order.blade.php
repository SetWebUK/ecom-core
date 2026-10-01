@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Hi {{ $order->billing_first_name ?: 'there' }},</p>
<p style="margin:0 0 16px;">Your order #{{ $order->number }} from {{ $storeName }} has been {{ ! empty($partial) ? 'partially refunded' : 'refunded' }}{{ ! empty($amount) ? ' ('.money($amount).')' : '' }}.</p>
@include('emails.partials.order-details')
@include('emails.partials.addresses')
<p style="margin:0;">Refunds usually reach your account within 5–10 working days, depending on your bank.</p>
@endsection
