{{-- /my-account/reset-password/{token}. $token, $email. POST route('password.update'): token, email, password, password_confirmation. --}}
@extends('auth.partials.shell')

@section('title', 'Choose a new password | '.setting('store.name', config('app.name')))

@section('auth_card')
    <header class="auth-card__head">
        <h1 class="auth-card__title">Choose a new password</h1>
        <p class="auth-card__lead">@if (old('email', $email))For <strong>{{ old('email', $email) }}</strong>. @endif Use at least 8 characters.</p>
    </header>
    @if ($errors->has('email') || $errors->has('token'))
        <div class="notice notice--error auth-alert" role="alert">{{ $errors->first('email') ?: $errors->first('token') }}</div>
    @endif
    <form class="form auth-form" data-auth-form method="post" action="{{ route('password.update') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ old('email', $email) }}">
        @include('auth.partials.password', ['id' => 'password_1', 'name' => 'password', 'label' => 'New password', 'autocomplete' => 'new-password', 'error' => $errors->first('password') ?: null, 'strength' => true, 'minlength' => 8])
        @include('auth.partials.password', ['id' => 'password_2', 'name' => 'password_confirmation', 'label' => 'Confirm new password', 'autocomplete' => 'new-password', 'match' => 'password_1'])
        <button type="submit" class="btn btn--primary btn--block btn--lg" value="Save">Save password</button>
    </form>
    <p class="auth-foot"><a href="{{ route('password.request') }}">Request a new link</a> · <a href="{{ route('account') }}">Sign in</a></p>
@endsection
