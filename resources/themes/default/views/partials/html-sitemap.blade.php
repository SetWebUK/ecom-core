{{-- HTML sitemap (sitemap shortcode): pages, product categories and blog posts. --}}
@php
    $sitemapData = cache()->remember('html-sitemap:default', 3600, function () {
        $pages = \Pine\Commerce\Models\Page::where('status', 'published')->where('noindex', false)
            ->where('template', '!=', 'home')->where('path', '!=', '')
            ->whereNotIn('path', \Pine\Commerce\Http\Controllers\SitemapController::EXCLUDED_PATHS)
            ->whereNotIn('path', ['shop', 'blog'])->where('template', '!=', 'blog')
            ->orderBy('title')->get(['id', 'title', 'path'])->map(fn ($p) => ['title' => $p->title, 'url' => $p->url])->all();
        $cats = \Pine\Commerce\Models\Category::where('is_visible', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'parent_id', 'name', 'path']);
        $tree = function ($parentId) use (&$tree, $cats) {
            return $cats->where('parent_id', $parentId)->map(fn ($c) => ['title' => $c->name, 'url' => $c->url, 'children' => $tree($c->id)])->values()->all();
        };
        $posts = \Pine\Commerce\Models\Post::published()->orderByDesc('published_at')->get(['id', 'title', 'slug'])->map(fn ($p) => ['title' => $p->title, 'url' => $p->url])->all();

        return ['pages' => $pages, 'categories' => $tree(null), 'posts' => $posts];
    });
    $renderTree = function (array $items) use (&$renderTree) {
        return '<ul>'.collect($items)->map(fn ($i) => '<li><a href="'.e($i['url']).'">'.e($i['title']).'</a>'.(! empty($i['children']) ? $renderTree($i['children']) : '').'</li>')->implode('').'</ul>';
    };
@endphp
<div class="html-sitemap">
    <section>
        <h2>Pages</h2>
        <ul>
            <li><a href="{{ url('/') }}/">Home</a></li>
            <li><a href="{{ url('shop') }}/">Shop</a></li>
            @if (commerce_feature('blog'))<li><a href="{{ url('blog') }}/">Blog</a></li>@endif
            @foreach ($sitemapData['pages'] as $item)
                <li><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></li>
            @endforeach
        </ul>
    </section>
    @if ($sitemapData['categories'])
        <section><h2>Product categories</h2>{!! $renderTree($sitemapData['categories']) !!}</section>
    @endif
    @if ($sitemapData['posts'] && commerce_feature('blog'))
        <section>
            <h2>Blog posts</h2>
            <ul>@foreach ($sitemapData['posts'] as $item)<li><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></li>@endforeach</ul>
        </section>
    @endif
</div>
