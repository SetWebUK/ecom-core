{{-- Blog post card. $post, optional $featured --}}
<article class="post-card {{ ! empty($featured) ? 'post-card--featured' : '' }}">
    <a class="post-card__media" href="{{ $post->url }}" tabindex="-1" aria-hidden="true">
        @if ($post->image_url)<x-media-image :path="$post->featured_image" size="medium" sizes="(min-width: 1100px) 400px, (min-width: 700px) 50vw, 100vw" />@endif
    </a>
    <div class="post-card__body">
        <p class="post-card__meta">
            @if ($post->category)<a href="{{ url('blog/category/'.$post->category->slug) }}/">{{ $post->category->name }}</a> · @endif
            <time datetime="{{ $post->published_at?->toDateString() }}">{{ $post->published_at?->format('j M Y') }}</time>
        </p>
        <h2 class="post-card__title"><a href="{{ $post->url }}">{{ $post->title }}</a></h2>
        <p class="post-card__excerpt">{{ \Pine\Commerce\View\Components\PageContent::excerpt($post->excerpt ?: $post->content, 28) }}</p>
    </div>
</article>
