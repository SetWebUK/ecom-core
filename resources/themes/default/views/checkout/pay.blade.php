{{-- Pay for a pending / failed order. $order, $gateways, $problem, $error, $termsUrl, $stripe, $noGatewaysMessage --}}
@extends('checkout.layout')

@php $S = \Pine\Commerce\Theme\Storefront::class; @endphp

@section('title', 'Pay for order | '.setting('store.name', config('app.name')))
@section('body_class', 'page-order-pay')

@if ($stripe && ! $problem)
    @push('head')
        <link rel="preconnect" href="https://js.stripe.com">
        <script src="https://js.stripe.com/v3/" defer></script>
    @endpush
@endif

@section('content')
<div class="container checkout">
    <div class="checkout__main">
        <h1 class="page-title">Pay for order #{{ $order->number }}</h1>
        <div class="checkout__alerts" aria-live="polite" data-alerts>
            @if ($error)<div class="notice notice--error" role="alert">{{ $error }}</div>@endif
            @if ($errors->any())<div class="notice notice--error" role="alert"><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
        </div>
        @if ($problem)
            <div class="notice notice--info">{{ $problem }}</div>
            <a class="btn btn--primary" href="{{ $order->view_url }}">View order</a>
        @else
            <dl class="details-list card">
                <div><dt>Order</dt><dd>#{{ $order->number }} · {{ \Pine\Commerce\Services\Checkout\UkTime::format($order->created_at, 'j F Y') }}</dd></div>
                <div><dt>Email</dt><dd>{{ $order->email }}</dd></div>
                <div><dt>Total</dt><dd><strong>{{ money($order->total) }}</strong></dd></div>
            </dl>
            <form id="order_review_form" class="checkout-step" method="POST" action="{{ route('checkout.pay.submit', ['order' => $order->number]) }}" novalidate data-order-pay>
                @csrf
                <input type="hidden" name="key" value="{{ $order->order_key }}">
                <h2 class="checkout-step__title">Payment</h2>
                @if ($gateways)
                    @include('checkout.partials.payment-methods', ['gateways' => $gateways, 'selected' => old('payment_method', array_key_exists((string) $order->payment_method, $gateways) ? $order->payment_method : array_key_first($gateways))])
                @else
                    <div class="notice notice--error" role="alert">{{ $noGatewaysMessage }}</div>
                @endif
                @if ($termsUrl)
                    <label class="check"><input type="checkbox" name="terms" id="terms" value="1" data-terms> <span>I have read and agree to the <a href="{{ $termsUrl }}" target="_blank" rel="noopener">terms and conditions</a> <span class="req" aria-hidden="true">*</span></span></label>
                @endif
                <button type="submit" class="btn btn--primary btn--lg btn--block" id="place_order" data-place-order @unless ($gateways) disabled @endunless>{!! $S::icon('lock', 18) !!}<span>Pay {{ money($order->total) }}</span></button>
                <p class="center"><a class="small" href="{{ $order->view_url }}">Back to order</a></p>
            </form>
        @endif
    </div>
    <aside class="checkout__aside" aria-label="Order summary">
        <div class="summary-card">
            <h2 class="card__title">Your order</h2>
            @include('checkout.partials.order-summary', ['order' => $order])
        </div>
    </aside>
</div>
<script type="application/json" id="checkout-config">{!! json_encode([
    'stripe' => $problem ? null : $stripe,
    'needsPayment' => ! $problem,
    'total' => (float) $order->total,
    'currency' => config('commerce.currency.code', 'GBP'),
    'orderPay' => true,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
