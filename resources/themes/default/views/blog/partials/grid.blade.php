{{-- Blog post grid (also rendered by the blog index shortcode). $posts (paginator), $featureFirst --}}
@if ($posts->isEmpty())
    <p class="empty">No posts yet – check back soon.</p>
@else
    <div class="post-grid">
        @foreach ($posts as $post)
            @include('blog.partials.card', ['post' => $post, 'featured' => ! empty($featureFirst) && $loop->first])
        @endforeach
    </div>
    {{ $posts->links() }}
@endif
