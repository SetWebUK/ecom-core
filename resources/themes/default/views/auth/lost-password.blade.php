{{-- /my-account/lost-password/. $sent (bool). POST route('password.email'): user_login. --}}
@extends('auth.partials.shell')

@section('title', 'Reset your password | '.setting('store.name', config('app.name')))

@section('auth_card')
    @if ($sent)
        <div class="auth-success" role="status">
            <span class="auth-success__icon" aria-hidden="true">{!! \Pine\Commerce\Theme\Storefront::icon('mail', 28) !!}</span>
            <h1 class="auth-card__title">Check your email</h1>
            <p class="auth-card__lead">If an account exists for that email address or username, we’ve sent a link to choose a new password. It can take a few minutes to arrive – check your spam folder too.</p>
        </div>
        <a class="btn btn--primary btn--block btn--lg" href="{{ route('account') }}">Back to sign in</a>
        <p class="auth-foot">Didn’t get it? <a href="{{ route('password.request') }}">Send another link</a></p>
    @else
        <header class="auth-card__head">
            <h1 class="auth-card__title">Reset your password</h1>
            <p class="auth-card__lead">Enter your email address or username and we’ll email you a link to choose a new password.</p>
        </header>
        <form class="form auth-form" data-auth-form method="post" action="{{ route('password.email') }}" novalidate>
            @csrf
            <div class="field auth-field {{ $errors->has('user_login') ? 'is-invalid' : '' }}">
                <label for="user_login">Email address or username <span class="req" aria-hidden="true">*</span></label>
                <input type="text" name="user_login" id="user_login" autocomplete="username" value="{{ old('user_login') }}" required aria-required="true" maxlength="190" autocapitalize="off" spellcheck="false"
                    @error('user_login') aria-invalid="true" aria-describedby="user_login-error" @enderror>
                @error('user_login')<p class="field__error" id="user_login-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="btn btn--primary btn--block btn--lg" value="Reset password">Send reset link</button>
        </form>
        <p class="auth-foot"><a href="{{ route('account') }}">← Back to sign in</a></p>
    @endif
@endsection
