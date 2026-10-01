@extends('commerce::admin.layouts.auth')

@section('title', 'Choose a new password')

@section('content')
    <h1 class="auth__title">Choose a new password</h1>
    <p class="auth__lead">At least 10 characters, with letters and numbers.</p>

    <form method="POST" action="{{ route('admin.password.update') }}" class="auth__form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-admin.input name="email" type="email" label="Email" :value="$email" autocomplete="username" required />
        <x-admin.input name="password" type="password" label="New password" autocomplete="new-password" required autofocus />
        <x-admin.input name="password_confirmation" type="password" label="Confirm new password" autocomplete="new-password" required />
        <x-admin.button type="submit" variant="primary" size="lg" block>Save password</x-admin.button>
    </form>
@endsection

@section('footer')
    <a href="{{ route('admin.login') }}">← Back to sign in</a>
@endsection
