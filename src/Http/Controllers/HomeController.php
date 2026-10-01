<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Models\Page;
use Pine\Commerce\View\Components\Seo;
use Pine\Commerce\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Home page (theme contract key "home"). Also answers the legacy WordPress search URL /?s=term.
 *
 * Editable content lives on the Page with template "home" as JSON blocks ($page->blocks). Any block (or key inside
 * it) that is missing falls back to the active theme's defaults (theme config home.defaults); which blocks exist and
 * how the admin edits them is the theme's "home" block schema (theme config blocks.schemas.home, see PageBlocks).
 * Theme config also provides home.best_sellers.legacy_wp_ids (product grid until products are featured) and
 * home.seo.description. Typical blocks (default theme):
 *
 *   usp_bar      [ {icon, text} ]                                  strip under the header
 *   hero         {eyebrow, title, text, button_text, button_url, trust_line, image}
 *   features     [ {icon, title, text} ]                           tiles under the hero
 *   categories   {title, tiles: [ {title, url, image} ]}
 *   best_sellers {title, text, limit, product_ids: [ids]}          product_ids empty = featured products
 *   why / benefits / brands / faq / cta                            text + image, cards, logos, Q&A, closing banner
 *
 * Images are public-disk paths ("uploads/2026/05/x.png") or absolute URLs. Titles may contain line breaks (\n).
 */
class HomeController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('s') && class_exists(SearchController::class)) {
            return app()->call([app(SearchController::class), 'index']);
        }

        $page = Page::where('template', 'home')->where('status', 'published')->first()
            ?? Page::where('path', '')->where('status', 'published')->first();
        $blocks = static::blocks($page?->blocks ?? []);

        return theme_view(['home', 'pages.home'], [
            'page' => $page,
            'b' => $blocks,
            'bestSellers' => $this->bestSellers($blocks['best_sellers']),
            'seo' => [
                'title' => $page?->meta_title ?: Seo::title('Home'),
                'description' => $page?->meta_description ?: theme_config('home.seo.description') ?: setting('seo.default_description'),
                'canonical' => url('/').'/',
                'noindex' => (bool) $page?->noindex,
                'modified_time' => optional($page?->updated_at)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Merge stored blocks over the defaults (lists are replaced as a whole when provided). Stored blocks the theme has
     * no defaults for are passed through unchanged.
     */
    public static function blocks(array $stored): array
    {
        $defaults = static::defaults();
        $out = $defaults + array_filter($stored, fn ($value) => $value !== null);
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $stored) || $stored[$key] === null) {
                continue;
            }
            $value = $stored[$key];
            if (array_is_list($default)) {
                $out[$key] = is_array($value) && $value ? array_values($value) : $default;

                continue;
            }
            if (is_array($value)) {
                foreach ($default as $k => $v) {
                    if (array_key_exists($k, $value) && $value[$k] !== null && $value[$k] !== '' && $value[$k] !== []) {
                        $out[$key][$k] = $value[$k];
                    }
                }
            }
        }

        return $out;
    }

    protected function bestSellers(array $config): Collection
    {
        $limit = max(1, min(24, (int) ($config['limit'] ?? 8)));
        $base = fn () => Product::published()->with(['images', 'primaryCategory', 'categories']);

        $ids = collect($config['product_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
        if ($ids->isNotEmpty()) {
            $products = $base()->whereIn('id', $ids)->get()->sortBy(fn ($p) => $ids->search($p->id))->values();
            if ($products->isNotEmpty()) {
                return $products->take($limit);
            }
        }

        $featured = $base()->where('is_featured', true)->orderBy('sort_order')->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get();
        if ($featured->isNotEmpty()) {
            return $featured;
        }

        $legacy = collect((array) theme_config('home.best_sellers.legacy_wp_ids', []))->map(fn ($id) => (int) $id)->filter()->values();
        $products = $legacy->isEmpty() ? collect()
            : $base()->whereIn('wp_id', $legacy)->get()->sortBy(fn ($p) => $legacy->search($p->wp_id))->values();
        if ($products->isNotEmpty()) {
            return $products->take($limit);
        }

        return $base()->inStock()->orderByDesc('total_sales')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Default home blocks of the active theme (theme config home.defaults; the default theme's are neutral and mostly
     * empty, a client theme ships its live copy).
     */
    public static function defaults(): array
    {
        $defaults = theme_config('home.defaults', []);

        return is_array($defaults) ? $defaults : [];
    }
}
