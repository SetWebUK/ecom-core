{{-- Minimal centred layout for sign-in, password reset and "no access" pages. --}}
@php
    $storeName = setting('store.name', config('app.name'));
    $assetVersion = fn (string $path) => commerce_admin_asset($path);
@endphp
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="admin-asset-base" content="{{ rtrim(commerce_admin_asset('', false), '/') }}/">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ $storeName }} admin</title>
    <link rel="icon" href="{{ commerce_admin_brand('favicon') }}" sizes="32x32">
    <link rel="preload" href="{{ commerce_admin_asset('vendor/inter/inter-latin-wght-normal.woff2', false) }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ $assetVersion('css/admin.css') }}">
    <script defer src="{{ $assetVersion('js/admin.js') }}"></script>
    <script defer src="{{ commerce_admin_asset('vendor/alpinejs/alpine-3.17.4.min.js', false) }}"></script>
</head>
<body>
    <main class="auth">
        <div class="auth__card">
            <div class="auth__brand">
                <img src="{{ commerce_admin_brand('logo') }}" alt="{{ $storeName }}" width="171" height="44">
            </div>
            <div class="auth__panel">
                @yield('content')
            </div>
            <p class="auth__footer">
                @hasSection('footer')
                    @yield('footer')
                @else
                    <a href="{{ url('/') }}">← Back to {{ $storeName }}</a>
                @endif
            </p>
        </div>
    </main>
    @include('commerce::admin.partials.toasts')
</body>
</html>
