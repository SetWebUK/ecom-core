@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Hi {{ $order->billing_first_name ?: 'there' }},</p>
<p style="margin:0 0 8px;">The following note has been added to your order:</p>
<blockquote style="margin:0 0 24px;padding:12px 16px;border-left:4px solid #e5e7eb;background:#f9fafb;">{!! nl2br(e($note ?? '')) !!}</blockquote>
@include('emails.partials.order-details')
@include('emails.partials.addresses')
@endsection
