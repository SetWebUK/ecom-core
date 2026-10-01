{{-- Content page (PageController@show): $page, $contentHtml, $isElementor, $showTitle, $pageCss, $seo, $bodyClass. --}}
@extends('layouts.app')

@section('body_class', 'page-content page-'.($page->template ?: 'default').' '.($isElementor ? 'is-legacy-layout' : ''))

@push('head')
    @if (! empty($pageCss))
        <link rel="stylesheet" href="{{ $pageCss }}">
    @endif
@endpush

@section('content')
    <div class="page-head">
        <div class="container {{ $isElementor ? '' : 'container--narrow' }}">
            @include('partials.breadcrumbs', ['crumbs' => [['label' => $page->title]]])
            @if ($showTitle || $isElementor)
                <h1 class="page-title">{{ $page->title }}</h1>
            @endif
        </div>
    </div>
    <div class="container {{ $isElementor ? 'legacy-content' : 'container--narrow' }} page-body">
        @yield('before_content')
        @if (trim($contentHtml) !== '')
            <div class="{{ $isElementor ? 'legacy' : 'prose' }}">{!! $contentHtml !!}</div>
        @endif
        @yield('after_content')
    </div>
@endsection
