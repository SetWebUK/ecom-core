@extends('commerce::admin.layouts.auth')

@section('title', $title)

@section('content')
    <div class="text-center stack stack--sm">
        <div class="error-page__code">{{ $status }}</div>
        <h1 class="auth__title">{{ $title }}</h1>
        @if ($detail)<p class="auth__lead">{{ $detail }}</p>@endif
    </div>
    <div class="auth__form">
        @auth
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="btn btn--primary btn--lg btn--block">Sign in with a staff account</button>
            </form>
        @else
            <a href="{{ route('admin.login') }}" class="btn btn--primary btn--lg btn--block">Sign in</a>
        @endauth
    </div>
@endsection
