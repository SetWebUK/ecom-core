@extends('account.frame')

@section('account_content')
<div class="card center">
    <h1 class="page-title">Sign out?</h1>
    <p class="muted">Are you sure you want to sign out?</p>
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn--primary">Sign out</button>
        <a class="btn btn--ghost" href="{{ route('account') }}">Cancel</a>
    </form>
</div>
@endsection
