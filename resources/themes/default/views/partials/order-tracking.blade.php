{{-- Order tracking form + result (order_tracking shortcode) – posts to ContactController@trackOrder. --}}
@php
    $trackedId = session('tracked_order_id');
    $tracked = $trackedId ? \Pine\Commerce\Models\Order::with('items')->find($trackedId) : null;
    $trackError = isset($errors) ? $errors->getBag('tracking')->first() : null;
    $trackingUrl = $tracked ? \Pine\Commerce\Mail\CustomerCompletedOrder::trackingUrl($tracked->tracking_carrier, $tracked->tracking_number) : null;
@endphp
<div class="order-tracking card" id="order-tracking">
    @if ($trackError)
        <div class="notice notice--error" role="alert">{{ $trackError }}</div>
    @endif
    @if ($tracked)
        <div class="order-tracking__result">
            <p>Order <strong>#{{ $tracked->number }}</strong> was placed on <strong>{{ \Pine\Commerce\Services\Checkout\UkTime::format($tracked->created_at, 'j F Y') }}</strong> and is currently <span class="status-pill status-pill--{{ $tracked->status }}">{{ $tracked->status_label }}</span>.</p>
            @if ($tracked->tracking_number)
                <p>Delivered by {{ $tracked->tracking_carrier ?: 'our courier' }} – tracking number @if ($trackingUrl)<a href="{{ $trackingUrl }}" target="_blank" rel="noopener">{{ $tracked->tracking_number }}</a>@else<strong>{{ $tracked->tracking_number }}</strong>@endif</p>
            @endif
            @include('checkout.partials.order-summary', ['order' => $tracked, 'compact' => true])
        </div>
    @endif
    <form action="{{ route('order.track') }}" method="post" class="form">
        @csrf
        <input type="hidden" name="page_url" value="{{ url()->current() }}">
        <p class="muted">Enter your order number and the email address you used at checkout.</p>
        <div class="form-grid">
            <div class="field"><label for="orderid">Order number</label><input type="text" name="orderid" id="orderid" value="{{ old('orderid') }}" required maxlength="40" placeholder="e.g. 10452"></div>
            <div class="field"><label for="order_email">Billing email</label><input type="email" name="order_email" id="order_email" value="{{ old('order_email') }}" required maxlength="190" autocomplete="email"></div>
        </div>
        <button type="submit" class="btn btn--primary" name="track" value="Track">Track order</button>
    </form>
</div>
