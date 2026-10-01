@extends('account.frame')

@section('account_content')
@php
    $countries = \Pine\Commerce\Services\Checkout\CheckoutService::countries();
    $address = function (string $type) use ($order, $countries) {
        $lines = $type === 'billing' ? $order->billingAddressLines() : $order->shippingAddressLines();
        $country = $order->{$type.'_country'};

        return implode('<br>', array_map(fn ($v) => e($v === $country ? ($countries[$v] ?? $v) : $v), $lines));
    };
    $trackingUrl = \Pine\Commerce\Mail\CustomerCompletedOrder::trackingUrl($order->tracking_carrier, $order->tracking_number);
@endphp
<p><a class="link-back" href="{{ route('account.orders') }}">← All orders</a></p>
<h1 class="page-title">Order #{{ $order->number }}</h1>
<p>Placed on {{ \Pine\Commerce\Services\Checkout\UkTime::format($order->created_at, 'j F Y') }} · <span class="status-pill status-pill--{{ $order->status }}">{{ $order->status_label }}</span></p>
@if (in_array($order->status, ['pending', 'failed'], true) && (float) $order->total > 0 && $order->created_via === 'checkout')
    <p><a class="btn btn--primary" href="{{ route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]) }}">Pay for this order</a></p>
@endif
@if ($invoiceUrl = \Pine\Commerce\Services\Invoices\Invoices::customerUrl($order, auth()->user()))
    <p class="invoice-download"><a class="btn btn--outline btn--sm" href="{{ $invoiceUrl }}" download>{!! \Pine\Commerce\Theme\Storefront::icon('download', 18) !!} Download invoice (PDF)</a></p>
@endif
@if ($order->tracking_number)
    <div class="card"><h2 class="card__title">Tracking</h2><p>{{ $order->tracking_carrier ? $order->tracking_carrier.': ' : '' }}@if ($trackingUrl)<a href="{{ $trackingUrl }}" target="_blank" rel="noopener">{{ $order->tracking_number }}</a>@else{{ $order->tracking_number }}@endif</p></div>
@endif
<div class="card">
    @include('checkout.partials.order-summary', ['order' => $order])
</div>
<div class="details-grid">
    <div class="card"><h2 class="card__title">Billing address</h2><address>{!! $address('billing') !!}</address></div>
    @if ($order->shipping_address_1)<div class="card"><h2 class="card__title">Delivery address</h2><address>{!! $address('shipping') !!}</address></div>@endif
</div>
@if ($order->notes->isNotEmpty())
    <div class="card">
        <h2 class="card__title">Order updates</h2>
        <ol class="timeline">
            @foreach ($order->notes as $note)
                <li><time>{{ \Pine\Commerce\Services\Checkout\UkTime::format($note->created_at, 'j F Y, H:i') }}</time><div>{!! nl2br(e($note->note)) !!}</div></li>
            @endforeach
        </ol>
    </div>
@endif
@endsection
