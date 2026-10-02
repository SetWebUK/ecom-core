{{--
    Shared frame of the sign-in / register / lost-password / reset-password pages: a centred card on a soft background.
    Views: @extends('auth.partials.shell') and fill @section('auth_card'); optional @section('auth_aside') (shown beside the
    card from 900px). Loads js/auth.js (password show/hide, strength, match).
--}}
@extends('layouts.app')

@section('robots', 'noindex, follow')
@section('body_class', 'page-auth')

@push('scripts')
    <script src="{{ theme_asset('js/auth.js') }}" defer></script>
@endpush

@section('content')
<div class="auth-page">
    <div class="container">
        <div class="auth-layout {{ $__env->hasSection('auth_aside') ? 'auth-layout--aside' : '' }}">
            <div class="auth-card">
                @if (session('account_notice'))<div class="notice notice--info" role="status">{{ session('account_notice') }}</div>@endif
                @if (session('account_message'))<div class="notice notice--success" role="status">{{ session('account_message') }}</div>@endif
                @yield('auth_card')
            </div>
            @hasSection('auth_aside')
                <aside class="auth-aside">@yield('auth_aside')</aside>
            @endif
        </div>
    </div>
</div>
@endsection
