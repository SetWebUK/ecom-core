{{--
    One-page checkout. Posts exactly the fields every theme posts (CheckoutService): billing_email, createaccount,
    account_password, shipping_*, order_comments, shipping_method, payment_method, bill_to_different_address,
    billing_*, terms. js/checkout.js adds AJAX totals, coupons and payment (Stripe / PayPal / bank transfer); without
    JavaScript the form posts normally.
--}}
@extends('checkout.layout')

@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $needsPayment = $totals['needs_payment'];
    $selectedGateway = old('payment_method', array_key_first($gateways));
    $differentBilling = old('bill_to_different_address') === 'different_from_shipping';
    $allErrors = $errors->all();
    $storeName = setting('store.name', config('app.name'));
@endphp

@section('title', 'Checkout | '.$storeName)
@section('body_class', 'page-checkout')

@if ($stripe)
    @push('head')
        <link rel="preconnect" href="https://js.stripe.com">
        <script src="https://js.stripe.com/v3/" defer></script>
    @endpush
@endif

@section('content')
<form id="checkout" class="container checkout" method="POST" action="{{ route('checkout.place') }}" novalidate
      data-checkout data-update-url="{{ route('checkout.update') }}" data-coupon-url="{{ route('cart.coupon') }}">
    @csrf
    <div class="checkout__main">
        <h1 class="page-title">Checkout</h1>
        <div class="checkout__alerts" aria-live="polite" data-alerts>
            @if ($error)<div class="notice notice--error" role="alert">{{ $error }}</div>@endif
            @if ($allErrors)<div class="notice notice--error" role="alert"><ul>@foreach ($allErrors as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
            @foreach ($notices as $notice)<div class="notice notice--info">{{ $notice }}</div>@endforeach
        </div>

        <section class="checkout-step" aria-labelledby="step-contact">
            <div class="checkout-step__head">
                <h2 class="checkout-step__title" id="step-contact"><span class="checkout-step__n">1</span>Contact</h2>
                @guest<p class="small">Have an account? <a href="{{ route('account', ['redirect' => '/checkout/']) }}">Sign in</a></p>@endguest
            </div>
            <div class="form-grid">
                @include('checkout.partials.field', ['name' => 'billing_email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'maxlength' => 190, 'inputmode' => 'email', 'wide' => true])
            </div>
            @if (! auth()->check() && \Pine\Commerce\Http\Controllers\Auth\AuthController::registrationEnabled())
                <label class="check"><input type="checkbox" name="createaccount" value="1" @checked(old('createaccount')) data-create-account> <span>Create an account for faster checkout next time</span></label>
                <div class="field" id="account_password_field" @unless (old('createaccount')) hidden @endunless data-account-password>
                    <label for="account_password">Choose a password <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" name="account_password" id="account_password" autocomplete="new-password" minlength="8" maxlength="255">
                </div>
            @endif
        </section>

        <section class="checkout-step" aria-labelledby="step-address">
            <h2 class="checkout-step__title" id="step-address"><span class="checkout-step__n">2</span>Delivery address</h2>
            @include('checkout.partials.address-fields', ['prefix' => 'shipping'])
            <div class="field field--full">
                <label for="order_comments">Order notes <span class="opt">(optional)</span></label>
                <textarea name="order_comments" id="order_comments" rows="2" maxlength="2000" placeholder="Anything we should know about your delivery?">{{ old('order_comments') }}</textarea>
            </div>
        </section>

        <section class="checkout-step" aria-labelledby="step-shipping">
            <h2 class="checkout-step__title" id="step-shipping"><span class="checkout-step__n">3</span>Delivery method</h2>
            <div data-shipping-methods>
                @include('checkout.partials.shipping-methods', ['totals' => $totals])
            </div>
        </section>

        <section class="checkout-step" aria-labelledby="step-payment">
            <h2 class="checkout-step__title" id="step-payment"><span class="checkout-step__n">4</span>Payment</h2>
            <div data-payment-section @unless ($needsPayment) hidden @endunless>
                <p class="small muted">{!! $S::icon('lock', 16) !!} All transactions are secure and encrypted.</p>
                @if ($gateways)
                    @include('checkout.partials.payment-methods', ['gateways' => $gateways, 'selected' => $selectedGateway])
                @else
                    <div class="notice notice--error" role="alert">{{ $noGatewaysMessage }}</div>
                @endif
            </div>
            @unless ($needsPayment)<p class="notice notice--info">Your order does not require payment.</p>@endunless

            <h3 class="checkout-sub">Billing address</h3>
            <ul class="choice-list">
                <li class="choice {{ $differentBilling ? '' : 'is-selected' }}">
                    <input type="radio" name="bill_to_different_address" id="billing_same" value="same_as_shipping" @checked(! $differentBilling) data-billing-toggle>
                    <label for="billing_same"><span class="choice__title">Same as delivery address</span></label>
                </li>
                <li class="choice {{ $differentBilling ? 'is-selected' : '' }}">
                    <input type="radio" name="bill_to_different_address" id="billing_different" value="different_from_shipping" @checked($differentBilling) data-billing-toggle>
                    <label for="billing_different"><span class="choice__title">Use a different billing address</span></label>
                </li>
            </ul>
            <div class="billing-fields" id="billing-fields" @unless ($differentBilling) hidden @endunless data-billing-fields>
                @include('checkout.partials.address-fields', ['prefix' => 'billing'])
            </div>

            <p class="small muted">Your personal data will be used to process your order and for other purposes described in our <a href="{{ $privacyUrl }}" target="_blank" rel="noopener">privacy policy</a>.</p>
            @if ($termsUrl)
                <label class="check" id="terms_field"><input type="checkbox" name="terms" id="terms" value="1" @checked(old('terms')) data-terms> <span>I have read and agree to the <a href="{{ $termsUrl }}" target="_blank" rel="noopener">terms and conditions</a> <span class="req" aria-hidden="true">*</span></span></label>
            @endif

            <button type="submit" class="btn btn--primary btn--lg btn--block" id="place_order" name="woocommerce_checkout_place_order" value="Place order" data-place-order @if ($needsPayment && ! $gateways) disabled @endif>
                {!! $S::icon('lock', 18) !!}<span>Place order · <span data-order-total>{{ money($totals['total']) }}</span></span>
            </button>
            <p class="center"><a class="small" href="{{ url('shop') }}/">Continue shopping</a></p>
        </section>
    </div>

    <aside class="checkout__aside" aria-label="Order summary">
        <details class="checkout__summary-toggle" open data-summary-details>
            <summary><span>Order summary</span><strong data-order-total>{{ money($totals['total']) }}</strong></summary>
            <div data-summary>
                @include('checkout.partials.summary', ['cart' => $cart, 'totals' => $totals])
            </div>
        </details>
    </aside>
</form>

<script type="application/json" id="checkout-config">{!! json_encode([
    'stripe' => $stripe,
    'needsPayment' => $needsPayment,
    'total' => $totals['total'],
    'currency' => config('commerce.currency.code', 'GBP'),
    'items' => $totals['lines']->map(fn ($l) => ['item_id' => (string) ($l->sku() ?: $l->product->id), 'item_name' => $l->name(), 'price' => $l->unitPrice, 'quantity' => $l->quantity])->values(),
    'storeName' => $storeName,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
