{{-- "Order details / invoice" email (Pine\Commerce\Mail\Admin\CustomerInvoice) in the storefront's email layout. --}}
@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Hi {{ $order->billing_first_name ?: 'there' }},</p>
@if ($payUrl)
    <p style="margin:0 0 16px;">An order has been created for you on {{ $storeName }}. Your invoice is below, with a link to make payment when you’re ready:</p>
    <p style="margin:0 0 24px;">
        <a href="{{ $payUrl }}" style="display:inline-block;background:#FF6700;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:4px;">Pay for this order</a>
    </p>
@else
    <p style="margin:0 0 16px;">Here are the details of your order placed on {{ \Pine\Commerce\Services\Admin\LocalTime::format($order->created_at, 'j F Y') }}:</p>
@endif
@include('emails.partials.order-details')
@includeIf('emails.partials.addresses')
<p style="margin:0 0 16px;">Thanks for shopping with us.</p>
@endsection
