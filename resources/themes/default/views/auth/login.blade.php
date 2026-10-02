{{--
    /my-account/ for guests: sign in. $registration (bool: show "Create an account" → route('register.show')), $redirect.
    POST route('login.attempt'): username, password, rememberme, redirect. Errors come back on "username".
--}}
@extends('auth.partials.shell')

@section('title', 'Sign in | '.setting('store.name', config('app.name')))

@section('auth_card')
    @php $loginError = preg_replace('/^Error:\s*/', '', (string) ($errors->first('username') ?: $errors->first('password'))); @endphp
    <header class="auth-card__head">
        <h1 class="auth-card__title">Sign in</h1>
        <p class="auth-card__lead">Welcome back. Sign in to see your orders and check out faster.</p>
    </header>

    @if ($loginError)
        <div class="notice notice--error auth-alert" role="alert" id="login-error">{{ $loginError }}</div>
    @endif

    <form class="form auth-form" data-auth-form method="post" action="{{ route('login.attempt') }}" novalidate>
        @csrf
        @if ($redirect)<input type="hidden" name="redirect" value="{{ $redirect }}">@endif
        <div class="field auth-field {{ $loginError ? 'is-invalid' : '' }}">
            <label for="username">Email address or username <span class="req" aria-hidden="true">*</span></label>
            <input type="text" name="username" id="username" autocomplete="username" value="{{ old('username') }}" required aria-required="true" maxlength="190" autocapitalize="off" spellcheck="false"
                @if ($loginError) aria-invalid="true" aria-describedby="login-error" @endif>
        </div>
        @include('auth.partials.password', ['id' => 'password', 'name' => 'password', 'label' => 'Password', 'autocomplete' => 'current-password'])
        <div class="form-row-between auth-form__row">
            <label class="check"><input name="rememberme" type="checkbox" id="rememberme" value="forever" @checked(old('rememberme'))> <span>Remember me</span></label>
            <a class="small" href="{{ route('password.request') }}">Forgot your password?</a>
        </div>
        <button type="submit" class="btn btn--primary btn--block btn--lg" name="login" value="Log in">Sign in</button>
    </form>

    @if ($registration)
        <div class="auth-switch">
            <span>New here?</span>
            <a class="btn btn--outline btn--block" href="{{ route('register.show', $redirect ? ['redirect' => $redirect] : []) }}">Create an account</a>
        </div>
    @endif
@endsection
