{{-- Blog category archive. $category (PostCategory), $posts, $pageNumber --}}
@extends('layouts.app')

@section('body_class', 'page-blog page-blog-category')

@section('content')
    <div class="page-head">
        <div class="container">
            @include('partials.breadcrumbs', ['crumbs' => [['label' => 'Blog', 'url' => url('blog').'/'], ['label' => $category->name]]])
            <h1 class="page-title">{{ $category->name }}</h1>
        </div>
    </div>
    <div class="container page-body">
        @include('blog.partials.grid', ['posts' => $posts, 'featureFirst' => false])
    </div>
@endsection
