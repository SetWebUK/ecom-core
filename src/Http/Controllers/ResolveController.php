<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Models\Page;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Redirect;
use Pine\Commerce\Support\Features;
use Pine\Commerce\Services\Catalog\Categories;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Catch-all for WordPress-style URLs, resolved in this order:
 *   1. content page by path                          -> PageController@show
 *   2. product category by path (+ /page/N)          -> CatalogController@category
 *      legacy /product-category/{path}/              -> 301 to the category
 *   3. product at /{category path}/{slug}/           -> ProductController@show
 *      (a draft/private product is a 404 for customers; staff see it with a "not visible to customers" banner)
 *      the slug under any other category path, or legacy /product/{slug}/ -> 301 to the canonical URL
 *      (an explicit redirect rule for the product's own URL wins, like the WordPress Redirection plugin)
 *   4. redirects table (hit counted)                 -> 301/302/307/308, or 410 Gone (theme errors.410 page)
 *   5. WordPress 404 fallbacks: /{product-slug}/ -> 301 to the product (core redirect_guess_404_permalink),
 *      then the right-most path segment that is a category slug -> 301 to that category (Premmerce
 *      permalink manager, e.g. a deleted product /chargers/old-slug/ -> /chargers/)
 *   6. 404 page
 * Also: product search at /page/N/?s=, /search/?s=, /search/{term}/ (SearchController).
 * Legacy ?add-to-cart=ID links are handled for every storefront URL by the HandleAddToCartQuery middleware.
 */
class ResolveController extends Controller
{
    public function resolve(Request $request, string $path)
    {
        $path = trim($path, '/');
        if ($path === '' || strlen($path) > 500) {
            abort(404);
        }

        // Product search: /page/N/?s=term, /search/?s=term, /search/{term}/(page/N/)
        if (preg_match('#^(?:search/)?page/(\d{1,6})$#', $path, $m) && $request->has('s')) {
            return app(SearchController::class)->index($request, null, (int) $m[1]);
        }
        if (preg_match('#^search/(.+?)(?:/page/(\d{1,6}))?$#', $path, $m) && ! $request->has('s')) {
            return app(SearchController::class)->index($request, rawurldecode($m[1]), (int) ($m[2] ?? 1));
        }

        // 1. Pages (drafts only for staff previews - PageController enforces the same rule)
        $page = Page::where('path', $path)->first();
        if ($page && ($page->status === 'published' || $request->user()?->canAccessAdmin())) {
            return app(PageController::class)->show($page);
        }
        // the static front page's own slug (legacy /home-2/) 301s to the home page, like WordPress
        if (! $page && ! str_contains($path, '/') && Page::where('path', '')->where('slug', $path)->where('status', 'published')->exists()) {
            return redirect('/', 301);
        }

        // 2. Categories, optionally paginated: {path}/page/{n}
        $categoryPath = $path;
        $pageNumber = 1;
        $paged = false;
        if (preg_match('#^(.+)/page/(\d{1,6})$#', $path, $m)) {
            $categoryPath = $m[1];
            $pageNumber = (int) $m[2];
            $paged = true;
        }
        if (str_starts_with($categoryPath, 'product-category/')) {
            $legacy = Categories::byPath(substr($categoryPath, strlen('product-category/')));
            if ($legacy && $legacy->is_visible) {
                return $this->permanent($request, $legacy->url, $pageNumber);
            }
        }
        $category = Categories::byPath($categoryPath);
        if ($category && $category->is_visible) {
            if ($paged && $pageNumber <= 1) {
                return $this->permanent($request, $category->url);
            }
            if ($category->path !== $categoryPath) { // case differences
                return $this->permanent($request, $category->url, $pageNumber);
            }

            return app(CatalogController::class)->category($request, $category, $pageNumber);
        }

        // 3. Products
        if ($response = $this->product($request, $path)) {
            return $response;
        }

        // 4. Redirect rules
        if ($response = $this->redirect($request, $path)) {
            return $response;
        }

        // 5. WordPress 404 fallbacks
        if ($response = $this->guess($request, $path)) {
            return $response;
        }

        abort(404);
    }

    /**
     * WordPress answered some 404s with a 301: a bare /{product-slug}/ went to the product, and (Premmerce
     * permalink manager) any other path containing a product category slug went to that category.
     */
    protected function guess(Request $request, string $path)
    {
        if (! Features::enabled('wp_404_guess', false)) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', strtolower($path)), fn ($s) => $s !== ''));
        if (count($segments) === 1) {
            $product = Product::where('slug', $segments[0])->where('status', 'published')->with(['primaryCategory', 'categories'])->first();
            if ($product) {
                return $this->permanent($request, $product->url);
            }
        }
        $bySlug = Categories::all()->where('is_visible', true)->keyBy(fn ($c) => strtolower($c->slug));
        foreach (array_reverse($segments) as $segment) {
            if ($category = $bySlug->get(rawurldecode($segment))) {
                return $this->permanent($request, $category->url);
            }
        }

        return null;
    }

    protected function product(Request $request, string $path)
    {
        $segments = explode('/', $path);
        if (count($segments) < 2) {
            return null;
        }
        $slug = array_pop($segments);
        $prefix = implode('/', $segments);
        $isLegacy = $prefix === 'product';
        if (! $isLegacy && ! Categories::byPath($prefix)) {
            return null;
        }

        $product = Product::where('slug', $slug)->with(['primaryCategory', 'categories'])->first();
        if (! $product) {
            return null;
        }
        // A draft/private product is a 404 for customers (WordPress did the same) - never a 301 to its category through
        // the 404 fallbacks. An explicit redirect rule for the URL still wins. Staff preview it with a banner.
        if ($product->status !== 'published' && ! $request->user()?->canAccessAdmin()) {
            return $this->redirect($request, $path) ?? abort(404);
        }

        $canonical = trim((string) parse_url($product->url, PHP_URL_PATH), '/');
        if ($isLegacy || $canonical !== $path) {
            return $this->permanent($request, $product->url);
        }
        // A redirect rule set up for this very URL takes precedence (WordPress redirected before rendering).
        if ($response = $this->redirect($request, $path)) {
            return $response;
        }

        return app(ProductController::class)->show($request, $product);
    }

    protected function redirect(Request $request, string $path)
    {
        if (! Features::enabled('redirects', false)) {
            return null;
        }
        $from = strtolower(rawurldecode($path));
        $rule = Redirect::where('from_path', $from)->where('is_active', true)->first();
        if (! $rule) {
            return null;
        }
        DB::table('redirects')->where('id', $rule->id)->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => now()]);

        // 410 Gone: removed on purpose, no new address - the theme's "gone" page (errors.410, else its 404 page)
        if ((int) $rule->status_code === 410) {
            return $this->gone();
        }

        $target = $rule->to_url;
        if (! preg_match('#^https?://#i', $target)) {
            $target = url('/').'/'.ltrim($target, '/');
        }
        $status = in_array((int) $rule->status_code, [301, 302, 303, 307, 308], true) ? (int) $rule->status_code : 301;

        return redirect()->away($this->withQuery($request, $target), $status);
    }

    /** The theme's errors.410 page (falling back to its 404 page) with status 410. */
    protected function gone()
    {
        $view = View::exists('errors.410') ? 'errors.410' : 'errors.404';

        return response()->view($view, ['exception' => new HttpException(410, 'Gone')], 410);
    }

    /** 301 to $url (optionally page N), keeping the query string. */
    protected function permanent(Request $request, string $url, int $page = 1)
    {
        if ($page > 1) {
            $url = rtrim($url, '/').'/page/'.$page.'/';
        }

        return redirect()->away($this->withQuery($request, $url), 301);
    }

    protected function withQuery(Request $request, string $url): string
    {
        $query = $request->getQueryString();
        if (! $query || str_contains($url, '?')) {
            return $url;
        }

        return $url.'?'.$query;
    }
}
