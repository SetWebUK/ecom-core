{{-- Blog post. $post, $contentHtml, $previous, $next, $related --}}
@extends('layouts.app')

@php $S = \Pine\Commerce\Theme\Storefront::class; @endphp

@section('body_class', 'page-post')

@section('content')
    <article class="post">
        <header class="post__head container container--narrow">
            @include('partials.breadcrumbs', ['crumbs' => array_values(array_filter([['label' => 'Blog', 'url' => url('blog').'/'], $post->category ? ['label' => $post->category->name, 'url' => url('blog/category/'.$post->category->slug).'/'] : null, ['label' => $post->title]]))])
            <h1 class="page-title">{{ $post->title }}</h1>
            <p class="post__meta">
                <time datetime="{{ $post->published_at?->toDateString() }}">{{ $post->published_at?->format('j F Y') }}</time>
                @if ($post->author) · {{ $post->author->first_name ?: $post->author->name }}@endif
            </p>
        </header>
        @if ($post->image_url)
            <figure class="post__hero container"><x-media-image :path="$post->featured_image" size="large" sizes="(min-width: 1200px) 1200px, 100vw" :priority="true" /></figure>
        @endif
        <div class="container container--narrow">
            <div class="prose post__content">{!! $contentHtml !!}</div>
            <nav class="post-nav" aria-label="More posts">
                @if ($previous)<a class="post-nav__link" href="{{ $previous->url }}" rel="prev">{!! $S::icon('chevron-left', 18) !!}<span><small>Previous</small>{{ $previous->title }}</span></a>@else<span></span>@endif
                @if ($next)<a class="post-nav__link post-nav__link--next" href="{{ $next->url }}" rel="next"><span><small>Next</small>{{ $next->title }}</span>{!! $S::icon('chevron-right', 18) !!}</a>@endif
            </nav>
        </div>
    </article>
    @if ($related->isNotEmpty())
        <section class="section container" aria-labelledby="related-posts">
            <div class="section__head"><h2 class="section-title" id="related-posts">Keep reading</h2></div>
            <div class="post-grid post-grid--3">
                @foreach ($related as $item)
                    @include('blog.partials.card', ['post' => $item])
                @endforeach
            </div>
        </section>
    @endif
@endsection
