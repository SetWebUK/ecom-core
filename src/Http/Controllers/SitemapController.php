<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Models\Post;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Support\Features;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * /sitemap.xml (+ legacy Rank Math /sitemap_index.xml) and /robots.txt.
 */
class SitemapController extends Controller
{
    /** Page paths that never belong in the sitemap (shop flow / utility pages). */
    public const EXCLUDED_PATHS = ['basket', 'checkout', 'my-account', '404-2', 'support', 'sitemap'];

    public function index(): Response
    {
        // (the blog switch changes the content, so it is part of the cache key)
        $xml = cache()->remember(Features::enabled('blog') ? 'sitemap.xml' : 'sitemap.xml.no-blog', 3600, fn () => $this->build());

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, follow',
        ]);
    }

    public function robots(): Response
    {
        $custom = setting('seo.robots_txt', setting('seo.robots'));
        if (is_string($custom) && trim($custom) !== '') {
            $body = rtrim(str_replace("\r\n", "\n", $custom))."\n";
        } elseif (static::discouraged()) {
            // WordPress with "Discourage search engines" (legacy staging)
            $body = "User-agent: *\nDisallow: /\n";
        } else {
            $body = "User-agent: *\nDisallow: /admin/\nDisallow: /basket/\nDisallow: /checkout/\nDisallow: /my-account/\nDisallow: /cart/\nDisallow: /ajax/\n\nSitemap: ".url('sitemap_index.xml')."\n";
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * "Discourage search engines" (WordPress blog_public = 0): admin setting seo.discourage_search_engines;
     * while it has never been saved, staging hosts are discouraged (as the legacy staging site was).
     */
    public static function discouraged(): bool
    {
        $value = setting('seo.discourage_search_engines', setting('seo.discourage_search'));
        if ($value === null) {
            $host = request()->getHost();
            $value = str_contains($host, 'staging');
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    protected function build(): string
    {
        $urls = [];
        $add = function (string $loc, $lastmod = null, ?string $image = null) use (&$urls) {
            $urls[$loc] = ['loc' => $loc, 'lastmod' => $lastmod ? Carbon::parse($lastmod)->toAtomString() : null, 'image' => $image];
        };

        $latestProduct = Product::published()->max('updated_at');
        $latestPage = Page::where('status', 'published')->max('updated_at');
        $homeDates = array_filter([$latestProduct, $latestPage]);
        $add(url('/').'/', $homeDates ? max($homeDates) : null);

        Page::where('status', 'published')->where('noindex', false)
            ->where('template', '!=', 'home')->where('path', '!=', '')
            ->when(! Features::enabled('blog'), fn ($q) => $q->where('template', '!=', 'blog')->where('path', '!=', 'blog'))
            ->whereNotIn('path', self::EXCLUDED_PATHS)
            ->orderBy('sort_order')->orderBy('path')
            ->get(['id', 'path', 'template', 'updated_at'])
            ->each(fn (Page $p) => $add($p->url, $p->updated_at));

        $add(url('shop'), $latestProduct);
        $blog = Features::enabled('blog');
        if ($blog) {
            $add(url('blog'), Post::published()->max('updated_at'));
        }

        Category::where('is_visible', true)->orderBy('path')->get(['id', 'path', 'image', 'updated_at'])
            ->each(fn (Category $c) => $add($c->url, $c->updated_at, $c->image ? media_url($c->image) : null));

        Product::published()
            ->select(['id', 'slug', 'primary_category_id', 'updated_at'])
            ->with(['primaryCategory:id,path', 'categories:id,path', 'images' => fn ($q) => $q->select(['id', 'product_id', 'path', 'sort_order'])->orderBy('sort_order')])
            ->orderBy('id')
            ->chunk(500, function ($products) use ($add) {
                foreach ($products as $product) {
                    $image = $product->images->first();
                    $add($product->url, $product->updated_at, $image ? media_url($image->path) : null);
                }
            });

        $blog && Post::published()->orderByDesc('published_at')->get(['id', 'slug', 'featured_image', 'published_at', 'updated_at'])
            ->each(fn (Post $p) => $add($p->url, $p->updated_at ?? $p->published_at, $p->featured_image ? media_url($p->featured_image) : null));

        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
        foreach ($urls as $u) {
            $out .= "\t<url>\n\t\t<loc>".htmlspecialchars($u['loc'], ENT_XML1)."</loc>\n";
            if ($u['lastmod']) {
                $out .= "\t\t<lastmod>".$u['lastmod']."</lastmod>\n";
            }
            if ($u['image']) {
                $out .= "\t\t<image:image>\n\t\t\t<image:loc>".htmlspecialchars($u['image'], ENT_XML1)."</image:loc>\n\t\t</image:image>\n";
            }
            $out .= "\t</url>\n";
        }

        return $out.'</urlset>'."\n";
    }
}
