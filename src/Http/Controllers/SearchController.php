<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Models\Category;
use Pine\Commerce\Services\Catalog\Facets;
use Pine\Commerce\Services\Catalog\ProductListing;
use Pine\Commerce\Services\Catalog\Text;
use Pine\Commerce\View\Components\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Product search.
 *   /?s=term&post_type=product      (HomeController delegates here; /?s=term 302s to the product-scoped URL
 *                                    like the legacy search-router plugin)
 *   /page/N/?s=term, /search/?s=term, /search/{term}/   (ResolveController delegates the paged / pretty forms)
 * Results use the product archive view (commerce.catalog.per_page).
 *
 * GET /ajax/search?q=term - header live-search suggestions (site.js initSearch):
 *   {products: [{name, url, image, price_html, category}], categories: [{name, url}]}
 */
class SearchController extends Controller
{
    public function index(Request $request, ?string $term = null, int $page = 1)
    {
        // ?s[]=... (malformed). An empty ?s= arrives as null (ConvertEmptyStringsToNull) - that is a valid, empty search.
        if ($term === null && is_array($request->query('s'))) {
            return redirect()->away(url('/').'/?'.http_build_query(['s' => '', 'post_type' => 'product']), 302);
        }
        $raw = $term ?? $request->query('s', '');
        $search = is_string($raw) ? trim(preg_replace('/\s+/u', ' ', strip_tags($raw))) : '';
        $search = mb_substr($search, 0, 100);

        if ($term === null && $request->path() === '/' && ! $request->has('post_type') && $search !== '') {
            return redirect()->away(url('/').'/?'.http_build_query(['s' => $search, 'post_type' => 'product']), 302);
        }

        $listing = ProductListing::make()->searching($search)->withRequest($request, Facets::slugs());
        $label = $search === '' ? '' : Text::title($search);

        return app(CatalogController::class)->render($request, $listing, max(1, $page), [
            'context' => 'search',
            'baseUrl' => url('/'),
            'heading' => $search === '' ? 'Search results' : 'Search results for “'.$label.'”',
            'title' => Seo::title('You searched for '.$label),
            'pagedTitle' => fn (int $n, int $last) => Seo::title('You searched for '.$label.' | Page '.$n.' of '.$last),
            'description' => null,
            'bodyClass' => 'archive search search-results post-type-archive post-type-archive-product woocommerce-shop woocommerce woocommerce-page woocommerce-no-js',
            'breadcrumbs' => [['label' => 'Search results for “'.$label.'”']],
            'noindex' => true,
            'searchTerm' => $search,
            'canonical' => url('search/'.rawurlencode($search)),
        ]);
    }

    public function suggest(Request $request)
    {
        $q = $request->query('q', $request->query('s', ''));
        $q = is_string($q) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $q)), 0, 60) : '';
        if (mb_strlen($q) < 2) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        $data = Cache::remember('catalog.suggest:'.md5(mb_strtolower($q)), 120, function () use ($q) {
            $listing = ProductListing::make()->searching($q);
            $products = $listing->applySort($listing->filteredQuery())
                ->with(['images', 'primaryCategory', 'categories', 'variations'])
                ->limit(8)->get();

            $like = '%'.addcslashes($q, '%_\\').'%';
            $categories = Category::query()->where('is_visible', true)->where('name', 'like', $like)
                ->orderByRaw('CHAR_LENGTH(name)')->limit(4)->get();

            return [
                'products' => $products->map(fn ($p) => [
                    'name' => Text::title($p->name),
                    'url' => $p->url,
                    'image' => ($img = $p->images->first()) ? commerce_presenter()::sized($img->path, '150x150') : $p->image_url,
                    'price_html' => ($price = commerce_presenter()::price($p)) !== null ? commerce_presenter()::amount($price, false) : '',
                    'price_formatted' => $price !== null ? money($price) : '',
                    'category' => optional($p->primaryCategory ?? $p->categories->first())->name,
                ])->values()->all(),
                'categories' => $categories->map(fn (Category $c) => ['name' => $c->name, 'url' => $c->url])->values()->all(),
            ];
        });

        return response()->json($data)->header('Cache-Control', 'public, max-age=60');
    }
}
