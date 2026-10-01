<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Models\Page;
use Pine\Commerce\View\Components\Seo;
use Pine\Commerce\Models\Post;
use Pine\Commerce\Models\PostCategory;
use Pine\Commerce\View\Components\PageContent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Blog: /blog/ (+ /blog/page/N/), /blog/category/{slug}/ and single posts /blog/{slug}/.
 * The /blog/ page itself is the editable Page (path "blog"); its [blog_index] shortcode renders the post grid.
 */
class BlogController extends Controller
{
    public const PER_PAGE = 9;

    /** Category archives use the WordPress "Blog pages show at most" setting. */
    public const ARCHIVE_PER_PAGE = 12;

    public function index(Request $request, $page = null)
    {
        $pageNumber = $this->pageNumber($page);
        if ($pageNumber === 1 && $page !== null) {
            return redirect()->to(url('blog'), 301);
        }

        $blogPage = Page::where('path', 'blog')->where('status', 'published')->first();
        $content = $blogPage?->content;

        if ($blogPage && filled($content) && (\Pine\Commerce\Commerce::shortcodes()->usedIn('blog_index', $content) || str_contains($content, 'data-component="blog-index"'))) {
            $html = PageContent::process($content, $blogPage, ['blog_page' => $pageNumber]);
            $this->abortIfPastLastPage($pageNumber);

            return theme_view('blog.index', [
                'blogPage' => $blogPage,
                'contentHtml' => $html,
                'pageNumber' => $pageNumber,
                'seo' => $this->pageSeo($blogPage, $pageNumber),
            ]);
        }

        // No editable blog page: built-in layout matching the live page.
        $posts = $this->paginatedPosts(self::PER_PAGE, $pageNumber);
        if ($pageNumber > 1 && $posts->isEmpty()) {
            abort(404);
        }

        return theme_view('blog.index', [
            'blogPage' => $blogPage,
            'contentHtml' => null,
            'posts' => $posts,
            'pageNumber' => $pageNumber,
            'seo' => $this->pageSeo($blogPage, $pageNumber),
        ]);
    }

    public function category(Request $request, string $slug, $page = null)
    {
        $category = PostCategory::where('slug', $slug)->firstOrFail();
        $pageNumber = $this->pageNumber($page);
        if ($pageNumber === 1 && $page !== null) {
            return redirect()->to(url('blog/category/'.$category->slug), 301);
        }
        // legacy archive template 6367: WordPress "posts per page" (12) cards with author avatars
        $posts = Post::published()->with(['category', 'author'])
            ->where('post_category_id', $category->id)
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(self::ARCHIVE_PER_PAGE, ['id', 'post_category_id', 'author_id', 'title', 'slug', 'excerpt', 'content', 'featured_image', 'published_at', 'created_at'], 'page', $pageNumber)
            ->withPath(url('blog/category/'.$category->slug));
        if ($pageNumber > 1 && $posts->isEmpty()) {
            abort(404);
        }
        $siteName = setting('store.name', config('app.name'));

        return theme_view('blog.category', [
            'category' => $category,
            'posts' => $posts,
            'pageNumber' => $pageNumber,
            'seo' => [
                'title' => Seo::title($category->name.' Archives'.($pageNumber > 1 ? ' - Page '.$pageNumber : '')),
                'type' => 'article',
                'description' => 'Articles in '.$category->name.' from the '.$siteName.' blog.',
                'canonical' => $pageNumber > 1 ? url('blog/category/'.$category->slug.'/page/'.$pageNumber) : url('blog/category/'.$category->slug),
            ],
        ]);
    }

    public function show(Request $request, string $slug)
    {
        $post = Post::published()->with(['category', 'author'])->where('slug', $slug)->first();
        if (! $post) {
            // WordPress also served posts at their old /{slug}/ permalink; keep the legacy redirect table in charge of those.
            abort(404);
        }

        $publishedAt = $post->published_at ?? $post->created_at;
        $previous = Post::published()->where('id', '!=', $post->id)
            ->where(fn ($q) => $q->where('published_at', '<', $publishedAt)->orWhere(fn ($q) => $q->where('published_at', $publishedAt)->where('id', '<', $post->id)))
            ->orderByDesc('published_at')->orderByDesc('id')->first(['id', 'title', 'slug']);
        $next = Post::published()->where('id', '!=', $post->id)
            ->where(fn ($q) => $q->where('published_at', '>', $publishedAt)->orWhere(fn ($q) => $q->where('published_at', $publishedAt)->where('id', '>', $post->id)))
            ->orderBy('published_at')->orderBy('id')->first(['id', 'title', 'slug']);

        $related = $this->relatedPosts($post, 3);

        return theme_view('blog.show', [
            'post' => $post,
            'contentHtml' => PageContent::process((string) $post->content),
            'previous' => $previous,
            'next' => $next,
            'related' => $related,
            'seo' => [
                'title' => $post->meta_title ?: Seo::title($post->title),
                'description' => $post->meta_description ?: ($post->excerpt ? PageContent::excerpt($post->excerpt, 40) : PageContent::excerpt($post->content, 30)),
                'canonical' => $post->url,
                'type' => 'article',
                'image' => $post->image_url,
                'published_time' => optional($publishedAt)->toIso8601String(),
                'modified_time' => optional($post->updated_at)->toIso8601String(),
            ],
        ]);
    }

    /** RSS 2.0 feed of the latest posts at /feed/ (the legacy WordPress posts feed). */
    public function feed()
    {
        $posts = Post::published()->with(['category', 'author'])
            ->orderByDesc('published_at')->orderByDesc('id')->limit(10)
            ->get(['id', 'post_category_id', 'author_id', 'title', 'slug', 'excerpt', 'content', 'published_at', 'created_at', 'updated_at']);
        $siteName = setting('store.name', config('app.name'));
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $date = fn ($d) => $d ? $d->copy()->setTimezone('UTC')->format(DATE_RSS) : null;
        $last = $posts->max(fn ($p) => $p->updated_at ?? $p->published_at);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">'."\n<channel>\n"
            .'<title>'.$x($siteName).'</title>'."\n"
            .'<atom:link href="'.$x(url('feed')).'" rel="self" type="application/rss+xml" />'."\n"
            .'<link>'.$x(url('/').'/').'</link>'."\n"
            .'<description>'.$x(setting('seo.default_description', theme_config('seo.defaults.feed_description', ''))).'</description>'."\n"
            .($last ? '<lastBuildDate>'.$date($last).'</lastBuildDate>'."\n" : '')
            .'<language>en-GB</language>'."\n";
        foreach ($posts as $post) {
            $published = $post->published_at ?? $post->created_at;
            $xml .= "<item>\n"
                .'<title>'.$x(html_entity_decode($post->title, ENT_QUOTES | ENT_HTML5, 'UTF-8')).'</title>'."\n"
                .'<link>'.$x($post->url).'</link>'."\n"
                .($post->author ? '<dc:creator>'.$x($post->author->name).'</dc:creator>'."\n" : '')
                .($published ? '<pubDate>'.$date($published).'</pubDate>'."\n" : '')
                .($post->category ? '<category>'.$x($post->category->name).'</category>'."\n" : '')
                .'<guid isPermaLink="true">'.$x($post->url).'</guid>'."\n"
                .'<description>'.$x($post->excerpt ? PageContent::excerpt($post->excerpt, 55) : PageContent::excerpt($post->content, 55)).'</description>'."\n"
                ."</item>\n";
        }
        $xml .= "</channel>\n</rss>\n";

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /** HTML for the [blog_index] shortcode (grid + WordPress-style pagination). */
    public function renderIndexGrid(int $perPage = self::PER_PAGE, int $page = 1, ?PostCategory $category = null): string
    {
        $posts = $this->paginatedPosts($perPage, max(1, $page), $category);

        return theme_view('blog.partials.grid', ['posts' => $posts, 'featureFirst' => $page <= 1])->render();
    }

    public function paginatedPosts(int $perPage, int $page, ?PostCategory $category = null): LengthAwarePaginator
    {
        $query = Post::published()->with('category')
            ->when($category, fn ($q) => $q->where('post_category_id', $category->id))
            ->orderByDesc('published_at')->orderByDesc('id');

        return $query->paginate($perPage, ['id', 'post_category_id', 'title', 'slug', 'excerpt', 'content', 'featured_image', 'published_at', 'created_at'], 'page', $page)
            ->withPath($category ? url('blog/category/'.$category->slug) : url('blog'));
    }

    /** "More from the Blog": the latest posts other than this one (as the legacy Elementor posts widget). */
    protected function relatedPosts(Post $post, int $limit): Collection
    {
        return Post::published()->with(['category', 'author'])->where('id', '!=', $post->id)
            ->orderByDesc('published_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'post_category_id', 'author_id', 'title', 'slug', 'excerpt', 'content', 'featured_image', 'published_at', 'created_at']);
    }

    protected function pageNumber($page): int
    {
        if ($page === null) {
            return 1;
        }
        if (! ctype_digit((string) $page) || (int) $page < 1) {
            abort(404);
        }

        return (int) $page;
    }

    protected function abortIfPastLastPage(int $pageNumber): void
    {
        if ($pageNumber > 1 && $pageNumber > max(1, (int) ceil(Post::published()->count() / self::PER_PAGE))) {
            abort(404);
        }
    }

    protected function pageSeo(?Page $page, int $pageNumber): array
    {
        $title = $page?->meta_title ?: Seo::title('Blog');

        return [
            'title' => $pageNumber > 1 ? preg_replace('/^([^|]+)/', '$1- Page '.$pageNumber.' ', $title, 1) : $title,
            'description' => $page?->meta_description ?: theme_config('seo.defaults.blog_description') ?: setting('seo.default_description'),
            'canonical' => $pageNumber > 1 ? url('blog/page/'.$pageNumber) : url('blog'),
            'noindex' => (bool) $page?->noindex,
            'type' => 'article', // Rank Math tagged the blog page (a WP page) as an article
            'image' => preg_match('#<img[^>]+src="[^"]*?(/(?:wp-content|storage)/uploads/[^"]+\.(?:jpe?g|png|webp))"#i', (string) $page?->content, $m)
                ? url(str_replace('/wp-content/uploads/', '/storage/uploads/', $m[1])) : null,
        ];
    }
}
