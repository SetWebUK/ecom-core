@extends('account.frame')

@section('account_content')
<div class="notice notice--error" role="alert">We couldn’t find that order. <a href="{{ route('account.orders') }}">See your orders</a></div>
@endsection
