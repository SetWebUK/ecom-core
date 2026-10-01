{{-- /my-account/ for guests: sign in (+ register when enabled). $registration, $redirect --}}
@extends('account.frame', ['endpoint' => 'login'])

@section('account_content')
<div class="auth {{ $registration ? 'auth--split' : '' }}" id="customer_login">
    <section class="card auth__card">
        <h1 class="card__title card__title--lg">Sign in</h1>
        <form class="form" method="post" action="{{ route('login.attempt') }}" novalidate>
            @csrf
            @if ($redirect)<input type="hidden" name="redirect" value="{{ $redirect }}">@endif
            <div class="field"><label for="username">Email address <span class="req" aria-hidden="true">*</span></label><input type="text" name="username" id="username" autocomplete="username" value="{{ old('username') }}" required aria-required="true"></div>
            <div class="field"><label for="password">Password <span class="req" aria-hidden="true">*</span></label><input type="password" name="password" id="password" autocomplete="current-password" required aria-required="true"></div>
            <div class="form-row-between">
                <label class="check"><input name="rememberme" type="checkbox" id="rememberme" value="forever" @checked(old('rememberme'))> <span>Remember me</span></label>
                <a class="small" href="{{ route('password.request') }}">Forgot your password?</a>
            </div>
            <button type="submit" class="btn btn--primary btn--block" name="login" value="Log in">Sign in</button>
        </form>
    </section>
    @if ($registration)
        <section class="card auth__card">
            <h2 class="card__title card__title--lg">Create an account</h2>
            <p class="muted">Check out faster, track orders and save your addresses.</p>
            <form class="form" method="post" action="{{ route('register') }}" novalidate>
                @csrf
                @if ($redirect)<input type="hidden" name="redirect" value="{{ $redirect }}">@endif
                <div class="field"><label for="reg_email">Email address <span class="req" aria-hidden="true">*</span></label><input type="email" name="email" id="reg_email" autocomplete="email" value="{{ old('email') }}" required aria-required="true"></div>
                <div class="field"><label for="reg_password">Password <span class="req" aria-hidden="true">*</span></label><input type="password" name="password" id="reg_password" autocomplete="new-password" required aria-required="true" minlength="8"><p class="field__help">At least 8 characters.</p></div>
                <p class="small muted">Your personal data will be used to manage your account and for other purposes described in our privacy policy.</p>
                <button type="submit" class="btn btn--outline btn--block" name="register" value="Register">Create account</button>
            </form>
        </section>
    @endif
</div>
@endsection
