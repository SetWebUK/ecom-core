@extends('commerce::admin.layouts.auth')

@section('title', 'Sign in')

@section('content')
    <h1 class="auth__title">Sign in to your store</h1>
    <p class="auth__lead">Staff accounts only. Customers can sign in on the shop’s <a href="{{ Route::has('account') ? route('account') : url('/my-account') }}">My account</a> page.</p>

    @if (session('status'))
        <x-admin.callout type="success" class="mt-4">{{ session('status') }}</x-admin.callout>
    @endif
    @if (session('warning'))
        <x-admin.callout type="warning" class="mt-4">{{ session('warning') }}</x-admin.callout>
    @endif
    @if ($signedInCustomer && ! $errors->any())
        <x-admin.callout type="warning" class="mt-4">You’re signed in to the shop as {{ $signedInCustomer->email }}, which isn’t a staff account. Sign in with a staff account below.</x-admin.callout>
    @endif

    <form method="POST" action="{{ route('admin.login.store') }}" class="auth__form">
        @csrf
        <x-admin.input name="email" type="email" label="Email" autocomplete="username" required autofocus inputmode="email" />
        <x-admin.field label="Password" for="f-password" error="password">
            <x-slot:labelExtra><a href="{{ route('admin.password.request') }}" class="text-sm">Forgot password?</a></x-slot:labelExtra>
            <div x-data="{ show: false }" class="input-group">
                <input :type="show ? 'text' : 'password'" type="password" name="password" id="f-password" class="input" autocomplete="current-password" required>
                <button type="button" class="btn btn--ghost btn--sm" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'" :aria-pressed="show.toString()">
                    <span x-text="show ? 'Hide' : 'Show'">Show</span>
                </button>
            </div>
        </x-admin.field>
        <x-admin.checkbox name="remember" label="Keep me signed in on this device" :unchecked="null" />
        <x-admin.button type="submit" variant="primary" size="lg" block>Sign in</x-admin.button>
    </form>
@endsection
