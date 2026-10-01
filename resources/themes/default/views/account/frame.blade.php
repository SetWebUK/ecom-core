{{-- My account frame: sidebar navigation + content. Views fill @section('account_content'); $endpoint marks the active item. --}}
@extends('layouts.app')

@section('robots', 'noindex, follow')
@section('title', 'My account | '.setting('store.name', config('app.name')))
@section('body_class', 'page-account')

@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $endpoint = $endpoint ?? 'dashboard';
    $message = $message ?? session('account_message');
    $accountUser = auth()->user();
    $menu = $accountUser ? array_filter([
        'dashboard' => ['Dashboard', route('account'), 'home'],
        'orders' => ['Orders', route('account.orders'), 'bag'],
        'downloads' => ['Downloads', route('account.downloads'), 'download'],
        'edit-address' => ['Addresses', route('account.addresses'), 'map-pin'],
        'edit-account' => ['Account details', route('account.details'), 'user'],
        'wishlist' => commerce_feature('wishlist') ? ['Wishlist', route('account.wishlist'), 'heart'] : null,
        'customer-logout' => ['Sign out', \Pine\Commerce\Http\Controllers\Auth\AuthController::logoutUrl(), 'logout'],
    ]) : [];
    $displayName = $accountUser ? ($accountUser->first_name ?: $accountUser->name ?: $accountUser->email) : '';
@endphp

@section('content')
<div class="container account {{ $accountUser ? '' : 'account--guest' }}">
    @if ($accountUser)
        <aside class="account__nav">
            <p class="account__hello">Hello, <strong>{{ $displayName }}</strong></p>
            <nav aria-label="My account">
                <ul>
                    @foreach ($menu as $key => [$label, $href, $icon])
                        <li><a class="{{ $endpoint === $key ? 'is-active' : '' }}" href="{{ $href }}" @if ($endpoint === $key) aria-current="page" @endif>{!! $S::icon($icon, 20) !!}<span>{{ $label }}</span></a></li>
                    @endforeach
                </ul>
            </nav>
        </aside>
    @endif
    <div class="account__main">
        @if (session('account_notice'))<div class="notice notice--info" role="status">{{ session('account_notice') }}</div>@endif
        @if (! empty($message))<div class="notice notice--success" role="status">{{ $message }}</div>@endif
        @if ($errors->any())
            <div class="notice notice--error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @yield('account_content')
    </div>
</div>
@endsection
