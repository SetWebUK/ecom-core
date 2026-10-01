{{-- Order received. $order, $bacs, $error, $canPay --}}
@extends('checkout.layout')

@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $countries = \Pine\Commerce\Services\Checkout\CheckoutService::COUNTRIES;
    $addressHtml = function (string $type) use ($order, $countries) {
        $lines = $type === 'billing' ? $order->billingAddressLines() : $order->shippingAddressLines();
        $country = $order->{$type.'_country'};

        return implode('<br>', array_map(fn ($v) => e($v === $country ? ($countries[$v] ?? $v) : $v), $lines));
    };
    $trackingUrl = \Pine\Commerce\Mail\CustomerCompletedOrder::trackingUrl($order->tracking_carrier, $order->tracking_number);
    $isPaid = in_array($order->status, ['processing', 'completed', 'on-hold'], true);
    $payUrl = route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]);
@endphp

@section('title', 'Order received | '.setting('store.name', config('app.name')))
@section('body_class', 'page-order-received')

@section('content')
<div class="container checkout">
    <div class="checkout__main">
        @if ($error)<div class="notice notice--error" role="alert">{{ $error }}</div>@endif
        @if ($order->status === 'failed')
            <div class="notice notice--error" role="alert">Unfortunately your payment was declined, so the order could not be completed. Please try again.@if ($canPay) <a href="{{ $payUrl }}">Pay now</a>@endif</div>
        @elseif ($order->status === 'pending' && $canPay)
            <div class="notice notice--info">We have not received payment for this order yet. <a href="{{ $payUrl }}">Complete payment</a></div>
        @endif

        <div class="thanks">
            <span class="thanks__icon">{!! $S::icon('check-circle', 44) !!}</span>
            <div>
                <p class="eyebrow">Order #{{ $order->number }}</p>
                <h1 class="page-title">Thank you{{ $order->billing_first_name ? ', '.$order->billing_first_name : '' }}!</h1>
                <p class="muted">We’ve emailed a confirmation to <strong>{{ $order->email }}</strong>. You’ll get delivery updates by email too.</p>
            </div>
        </div>

        @if ($invoiceUrl = \Pine\Commerce\Services\Invoices\Invoices::customerUrl($order, auth()->user()))
            <p class="invoice-download"><a class="btn btn--outline btn--sm" href="{{ $invoiceUrl }}" download>{!! $S::icon('download', 18) !!} Download invoice (PDF)</a></p>
        @endif
        @if ($order->tracking_number)
            <section class="card">
                <h2 class="card__title">Tracking</h2>
                <p>{{ $order->tracking_carrier ? $order->tracking_carrier.': ' : '' }}@if ($trackingUrl)<a href="{{ $trackingUrl }}" target="_blank" rel="noopener">{{ $order->tracking_number }}</a>@else{{ $order->tracking_number }}@endif</p>
            </section>
        @endif

        @if ($bacs && in_array($order->status, ['on-hold', 'pending'], true))
            <section class="card">
                <h2 class="card__title">Pay by bank transfer</h2>
                <p>{!! nl2br(e($bacs->instructions() ?: $bacs->description())) !!}</p>
                @foreach ($bacs->accounts() as $account)
                    <dl class="details-list">
                        @if ($account['account_name'])<div><dt>Account name</dt><dd>{{ $account['account_name'] }}</dd></div>@endif
                        @if ($account['bank_name'])<div><dt>Bank</dt><dd>{{ $account['bank_name'] }}</dd></div>@endif
                        @if ($account['sort_code'])<div><dt>Sort code</dt><dd>{{ $account['sort_code'] }}</dd></div>@endif
                        @if ($account['account_number'])<div><dt>Account number</dt><dd>{{ $account['account_number'] }}</dd></div>@endif
                        @if ($account['iban'])<div><dt>IBAN</dt><dd>{{ $account['iban'] }}</dd></div>@endif
                        @if ($account['bic'])<div><dt>BIC</dt><dd>{{ $account['bic'] }}</dd></div>@endif
                    </dl>
                @endforeach
                <p>Please use <strong>{{ $order->number }}</strong> as the payment reference.</p>
            </section>
        @endif

        <section class="card">
            <h2 class="card__title">Order details</h2>
            <div class="details-grid">
                <div><h3>Contact</h3><p>{{ $order->email }}@if ($order->billing_phone)<br>{{ $order->billing_phone }}@endif</p></div>
                @if ($order->payment_method_title)<div><h3>Payment</h3><p>{{ $order->payment_method_title }}</p></div>@endif
                @if ($order->shipping_address_1)<div><h3>Delivery address</h3><address>{!! $addressHtml('shipping') !!}</address></div>@endif
                <div><h3>Billing address</h3><address>{!! $addressHtml('billing') !!}</address></div>
                @if ($order->shipping_method_title)<div><h3>Delivery method</h3><p>{{ $order->shipping_method_title }}</p></div>@endif
            </div>
        </section>

        @if ($order->notes->isNotEmpty())
            <section class="card">
                <h2 class="card__title">Order updates</h2>
                <ol class="timeline">
                    @foreach ($order->notes as $note)
                        <li><time>{{ \Pine\Commerce\Services\Checkout\UkTime::format($note->created_at, 'j F Y, H:i') }}</time><div>{!! nl2br(e($note->note)) !!}</div></li>
                    @endforeach
                </ol>
            </section>
        @endif

        <div class="actions-row">
            @auth<a class="btn btn--outline" href="{{ route('account.orders') }}">View your orders</a>@endauth
            <a class="btn btn--primary" href="{{ url('shop') }}/">Continue shopping</a>
        </div>
    </div>
    <aside class="checkout__aside" aria-label="Order summary">
        <div class="summary-card">
            <h2 class="card__title">Your order</h2>
            @include('checkout.partials.order-summary', ['order' => $order])
        </div>
    </aside>
</div>
@if ($isPaid)
    <script>
        (function () {
            var key = 'store_purchase_{{ $order->id }}';
            try { if (window.localStorage && localStorage.getItem(key)) { return; } } catch (e) {}
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push({!! json_encode(['event' => 'purchase', 'ecommerce' => [
                'transaction_id' => (string) $order->number, 'value' => (float) $order->total, 'tax' => (float) $order->tax_total,
                'shipping' => (float) $order->shipping_total, 'currency' => $order->currency ?: config('commerce.currency.code', 'GBP'), 'coupon' => (string) $order->coupon_code,
                'items' => $order->items->map(fn ($i) => ['item_id' => (string) ($i->sku ?: $i->product_id), 'item_name' => $i->name, 'price' => (float) $i->unit_price, 'quantity' => (int) $i->quantity])->values(),
            ]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!});
            try { localStorage.setItem(key, '1'); } catch (e) {}
        })();
    </script>
@endif
@endsection
