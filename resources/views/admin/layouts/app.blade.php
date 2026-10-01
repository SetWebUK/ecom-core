{{--
    Back-office layout.

    @extends('commerce::admin.layouts.app', ['width' => 'wide'])   width: default (1000px, detail/forms) | wide (lists) | narrow (settings) | full
    @section('title', 'Discounts')                        <title> prefix
    @section('content') … @endsection                      page body (start with <x-admin.page-header>)
    @push('head')     extra <link>/<style> in <head>
    @push('vendor')   extra deferred vendor <script> tags (loaded before admin.js and Alpine – components push their own)
    @push('scripts')  inline page scripts; register Alpine components inside document.addEventListener('alpine:init', …)

    Flash toasts: redirect()->with('success'|'error'|'warning'|'info', 'Message').
--}}
@php
    $storeName = setting('store.name', config('app.name'));
    $assetVersion = fn (string $path) => commerce_admin_asset($path);
    $pageClass = match ($width ?? null) { 'wide' => 'page--wide', 'narrow' => 'page--narrow', 'full' => 'page--full', default => '' };

    $flash = [];
    foreach (['success' => 'success', 'status' => 'success', 'error' => 'error', 'warning' => 'warning', 'info' => 'info'] as $key => $type) {
        if (session()->has($key) && is_scalar(session($key)) && (string) session($key) !== '') {
            $flash[] = ['type' => $type, 'message' => (string) session($key)];
        }
    }
    $errorMessages = collect($errors->getBags())->flatMap(fn ($bag) => $bag->all());
    if ($errorMessages->isNotEmpty() && ! session()->has('error')) {
        $flash[] = ['type' => 'error', 'message' => $errorMessages->count() === 1 ? $errorMessages->first() : 'Please fix the '.$errorMessages->count().' highlighted fields.'];
    }
@endphp
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="admin-asset-base" content="{{ rtrim(commerce_admin_asset('', false), '/') }}/">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#1a1a1a">
    {{-- @section('title', $x) already escapes $x (like @yield), so the yielded title is printed as-is here --}}
    @php $pageTitle = trim($__env->yieldContent('title')); @endphp
    <title>{!! $pageTitle !== '' ? $pageTitle.' · ' : '' !!}{{ $storeName }} admin</title>
    <link rel="icon" href="{{ commerce_admin_brand('favicon') }}" sizes="32x32">
    <link rel="preload" href="{{ commerce_admin_asset('vendor/inter/inter-latin-wght-normal.woff2', false) }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ $assetVersion('css/admin.css') }}">
    @stack('head')
    <script defer src="{{ commerce_admin_asset('vendor/alpinejs/collapse-3.17.4.min.js', false) }}"></script>
    <script defer src="{{ commerce_admin_asset('vendor/alpinejs/focus-3.17.4.min.js', false) }}"></script>
    @stack('vendor')
    <script defer src="{{ $assetVersion('js/admin.js') }}"></script>
    <script defer src="{{ commerce_admin_asset('vendor/alpinejs/alpine-3.17.4.min.js', false) }}"></script>
</head>
<body>
    <a href="#main" class="skip-link">Skip to content</a>
    <div class="app" x-data="shell" @keydown.escape.window="closeSidebar()">
        @include('commerce::admin.partials.topbar')
        <x-admin.sidebar />
        <main id="main" class="main" tabindex="-1">
            <div class="main__inner">
                <div class="page {{ $pageClass }}">
                    @yield('content')
                </div>
            </div>
        </main>
    </div>

    @include('commerce::admin.partials.toasts')
    @include('commerce::admin.partials.confirm')
    <script type="application/json" id="admin-flash">@json($flash)</script>
    @stack('scripts')
</body>
</html>
