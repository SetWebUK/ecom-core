{{-- /my-account/reset-password/{token}. $token, $email --}}
@extends('account.frame', ['endpoint' => 'lost-password'])

@section('account_content')
<section class="card auth__card auth__card--narrow">
    <h1 class="card__title card__title--lg">Choose a new password</h1>
    <form class="form" method="post" action="{{ route('password.update') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ old('email', $email) }}">
        <div class="field"><label for="password_1">New password <span class="req" aria-hidden="true">*</span></label><input type="password" name="password" id="password_1" autocomplete="new-password" required aria-required="true" minlength="8"></div>
        <div class="field"><label for="password_2">Confirm new password <span class="req" aria-hidden="true">*</span></label><input type="password" name="password_confirmation" id="password_2" autocomplete="new-password" required aria-required="true"></div>
        <button type="submit" class="btn btn--primary btn--block" value="Save">Save password</button>
    </form>
</section>
@endsection
