{{--
    /my-account/register/: create an account (only when registration is enabled). $registration (always true), $redirect.
    POST route('register'): email, password, first_name, last_name (optional), redirect. Validation errors return here.
--}}
@extends('auth.partials.shell')

@section('title', 'Create an account | '.setting('store.name', config('app.name')))

@section('auth_card')
    <header class="auth-card__head">
        <h1 class="auth-card__title">Create an account</h1>
        <p class="auth-card__lead">Track orders, save your addresses and check out faster.</p>
    </header>

    @if ($errors->any())
        <div class="notice notice--error auth-alert" role="alert">
            {{ $errors->count() === 1 ? preg_replace('/^Error:\s*/', '', $errors->first()) : 'Please check the highlighted fields below.' }}
        </div>
    @endif

    <form class="form auth-form" data-auth-form method="post" action="{{ route('register') }}" novalidate>
        @csrf
        @if ($redirect)<input type="hidden" name="redirect" value="{{ $redirect }}">@endif
        <div class="form-grid auth-form__names">
            <div class="field auth-field {{ $errors->has('first_name') ? 'is-invalid' : '' }}">
                <label for="reg_first_name">First name <span class="muted small">(optional)</span></label>
                <input type="text" name="first_name" id="reg_first_name" autocomplete="given-name" value="{{ old('first_name') }}" maxlength="100"
                    @error('first_name') aria-invalid="true" aria-describedby="reg_first_name-error" @enderror>
                @error('first_name')<p class="field__error" id="reg_first_name-error">{{ $message }}</p>@enderror
            </div>
            <div class="field auth-field {{ $errors->has('last_name') ? 'is-invalid' : '' }}">
                <label for="reg_last_name">Last name <span class="muted small">(optional)</span></label>
                <input type="text" name="last_name" id="reg_last_name" autocomplete="family-name" value="{{ old('last_name') }}" maxlength="100"
                    @error('last_name') aria-invalid="true" aria-describedby="reg_last_name-error" @enderror>
                @error('last_name')<p class="field__error" id="reg_last_name-error">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="field auth-field {{ $errors->has('email') ? 'is-invalid' : '' }}">
            <label for="reg_email">Email address <span class="req" aria-hidden="true">*</span></label>
            <input type="email" name="email" id="reg_email" autocomplete="email" value="{{ old('email') }}" required aria-required="true" maxlength="190" autocapitalize="off" spellcheck="false"
                @error('email') aria-invalid="true" aria-describedby="reg_email-error" @enderror>
            @error('email')<p class="field__error" id="reg_email-error">{{ preg_replace('/^Error:\s*/', '', $message) }}</p>@enderror
        </div>
        @include('auth.partials.password', ['id' => 'reg_password', 'name' => 'password', 'label' => 'Password', 'autocomplete' => 'new-password', 'error' => preg_replace('/^Error:\s*/', '', $errors->first('password')) ?: null, 'strength' => true, 'minlength' => 8])
        <p class="small muted auth-form__privacy">We use your details to run your account, as described in our <a href="{{ url('privacy-policy') }}">privacy policy</a>.</p>
        <button type="submit" class="btn btn--primary btn--block btn--lg" name="register" value="Register">Create account</button>
    </form>

    <p class="auth-foot">Already have an account? <a href="{{ route('account', $redirect ? ['redirect' => $redirect] : []) }}">Sign in</a></p>
@endsection
