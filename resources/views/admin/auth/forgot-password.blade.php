@extends('commerce::admin.layouts.auth')

@section('title', 'Reset your password')

@section('content')
    <h1 class="auth__title">Reset your password</h1>
    <p class="auth__lead">Enter the email address of your staff account and we’ll email you a link to choose a new password.</p>

    @if (session('status'))
        <x-admin.callout type="success" class="mt-4">{{ session('status') }}</x-admin.callout>
    @endif

    <form method="POST" action="{{ route('admin.password.email') }}" class="auth__form">
        @csrf
        <x-admin.input name="email" type="email" label="Email" autocomplete="username" required autofocus />
        <x-admin.button type="submit" variant="primary" size="lg" block>Email me a reset link</x-admin.button>
    </form>
@endsection

@section('footer')
    <a href="{{ route('admin.login') }}">← Back to sign in</a>
@endsection
