{{-- Full-width content page template (same data as pages.default). --}}
@extends('layouts.app')

@section('body_class', 'page-content page-full-width')

@push('head')
    @if (! empty($pageCss))
        <link rel="stylesheet" href="{{ $pageCss }}">
    @endif
@endpush

@section('content')
    @if ($showTitle)
        <div class="page-head"><div class="container"><h1 class="page-title">{{ $page->title }}</h1></div></div>
    @endif
    <div class="page-body page-body--full">{!! $contentHtml !!}</div>
@endsection
