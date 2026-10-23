@extends('account.frame')

@section('account_content')
@php $S = \Pine\Commerce\Theme\Storefront::class; @endphp
<h1 class="page-title">My account</h1>
<p class="muted">From here you can see your orders, manage your addresses and update your account details.</p>
<ul class="tile-grid">
    <li><a class="tile" href="{{ route('account.orders') }}">{!! $S::icon('bag', 28) !!}<strong>Orders</strong><span>Track, view and pay for orders</span></a></li>
    <li><a class="tile" href="{{ route('account.addresses') }}">{!! $S::icon('map-pin', 28) !!}<strong>Addresses</strong><span>Billing and delivery addresses</span></a></li>
    <li><a class="tile" href="{{ route('account.details') }}">{!! $S::icon('user', 28) !!}<strong>Account details</strong><span>Name, email and password</span></a></li>
    @if (commerce_feature('wishlist'))
        <li><a class="tile" href="{{ route('account.wishlist') }}">{!! $S::icon('heart', 28) !!}<strong>Wishlist</strong><span>Products you have saved</span></a></li>
    @endif
    @if (commerce_feature('loyalty'))
        @php $points = \Pine\Commerce\Services\Loyalty\Loyalty::balance(auth()->id()); @endphp
        <li><a class="tile" href="{{ route('account.points') }}">{!! $S::icon('star', 28) !!}<strong>{{ number_format($points) }} {{ \Pine\Commerce\Services\Loyalty\Loyalty::label() }}</strong><span>Worth {{ money(\Pine\Commerce\Services\Loyalty\Loyalty::value($points)) }} off – see how to earn more</span></a></li>
    @endif
</ul>
@endsection
