{{-- Order received, opened outside the browser that placed the order: confirm the billing email first (WooCommerce 8.x). $order, $action, $key, $error --}}
@extends('checkout.layout')

@section('title', 'Order received | '.setting('store.name', config('app.name')))
@section('body_class', 'page-order-received page-order-verify')

@section('content')
<div class="container container--narrow">
    <div class="card verify-order">
        <h1 class="page-title">Order #{{ $order->number }}</h1>
        @if ($error)<div class="notice notice--error" role="alert">{{ $error }}</div>@endif
        <p class="muted">To view this page, confirm the email address used for the order.</p>
        <form class="form" method="POST" action="{{ $action }}" novalidate>
            @csrf
            <input type="hidden" name="key" value="{{ $key }}">
            <div class="field"><label for="verify-email">Email address <span class="req" aria-hidden="true">*</span></label><input type="email" name="email" id="verify-email" autocomplete="email" inputmode="email" maxlength="190" required aria-required="true"></div>
            <button type="submit" class="btn btn--primary">Verify</button>
        </form>
    </div>
</div>
@endsection
