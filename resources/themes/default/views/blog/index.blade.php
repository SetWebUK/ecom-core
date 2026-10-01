{{-- Blog index (BlogController@index): the blog page's own content (with the grid shortcode) or the post grid. --}}
@extends('layouts.app')

@section('body_class', 'page-blog')

@section('content')
    <div class="page-head">
        <div class="container">
            @include('partials.breadcrumbs', ['crumbs' => [['label' => $blogPage?->title ?: 'Blog']]])
            <h1 class="page-title">{{ $blogPage?->title ?: 'Blog' }}@if ($pageNumber > 1) <span class="muted">– page {{ $pageNumber }}</span>@endif</h1>
        </div>
    </div>
    <div class="container page-body">
        @if (! empty($contentHtml))
            <div class="blog-content">{!! $contentHtml !!}</div>
        @else
            @include('blog.partials.grid', ['posts' => $posts, 'featureFirst' => $pageNumber <= 1])
        @endif
    </div>
@endsection
