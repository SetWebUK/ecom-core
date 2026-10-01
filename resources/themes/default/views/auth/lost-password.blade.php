{{-- /my-account/lost-password/. $sent --}}
@extends('account.frame', ['endpoint' => 'lost-password'])

@section('account_content')
<section class="card auth__card auth__card--narrow">
    @if ($sent)
        <h1 class="card__title card__title--lg">Check your email</h1>
        <p>If an account exists for that address, we’ve sent a link to reset your password. It may take a few minutes to arrive.</p>
        <p><a href="{{ route('account') }}">← Back to sign in</a></p>
    @else
        <h1 class="card__title card__title--lg">Reset your password</h1>
        <p class="muted">Enter your email address and we’ll send you a link to choose a new password.</p>
        <form class="form" method="post" action="{{ route('password.email') }}" novalidate>
            @csrf
            <div class="field"><label for="user_login">Email address</label><input type="text" name="user_login" id="user_login" autocomplete="username" value="{{ old('user_login') }}" required aria-required="true"></div>
            <button type="submit" class="btn btn--primary btn--block" value="Reset password">Send reset link</button>
        </form>
        <p class="center"><a class="small" href="{{ route('account') }}">Back to sign in</a></p>
    @endif
</section>
@endsection
