{{--
    Abandoned-cart reminders: unsubscribe (Pine\Commerce\Http\Controllers\CartRecoveryController@unsubscribe).
    Data: $done (already unsubscribed), $email (may be null), $action (the signed URL to POST to), $storeName
    Client themes inherit this view; override cart/unsubscribe.blade.php to restyle it.
--}}
@extends('layouts.app')

@section('title', 'Basket reminders | '.$storeName)
@section('robots', 'noindex, nofollow')
@section('body_class', 'page-unsubscribe')

@section('content')
<div class="container container--narrow error-page">
    <h1 class="page-title">Basket reminders</h1>
    @if ($done)
        <div class="notice notice--success" role="status">
            {{ $email ? $email.' won’t' : 'You won’t' }} get any more basket reminder emails from {{ $storeName }}.
        </div>
        <p class="muted">Emails about your orders are not affected.</p>
        <p><a class="btn btn--primary" href="{{ url('/') }}/">Continue shopping</a></p>
    @else
        <p class="muted">Stop emails reminding {{ $email ?: 'you' }} about baskets left at {{ $storeName }}? Emails about your orders are not affected.</p>
        <form method="post" action="{{ $action }}">
            @csrf
            <button type="submit" class="btn btn--primary">Unsubscribe from basket reminders</button>
        </form>
    @endif
</div>
@endsection
