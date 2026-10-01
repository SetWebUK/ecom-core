<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Theme\ThemeManager;
use Pine\Commerce\Services\Catalog\Categories;
use Pine\Commerce\Services\Catalog\Facets;
use Pine\Commerce\Services\Catalog\ProductListing;
use Pine\Commerce\Services\Catalog\Text;
use Pine\Commerce\View\Components\Seo;
use Illuminate\Http\Request;

/**
 * Product archives - the shop page (/shop/) and product categories (/{path}/, /{path}/page/N/),
 * rendered as a WooCommerce-style product archive: filter sidebar, results count, sort select, list/grid loop of
 * product cards, numbered pagination (commerce.catalog.per_page).
 * Search results (SearchController) use the same view.
 *
 * AJAX: requests sent with the commerce.catalog.ajax_header header (value 1) get JSON {sidebar, results, title, url, total} so
 * catalog.js can refresh filters/results without a full page load.
 */
class CatalogController extends Controller
{
    public function shop(Request $request, $page = null)
    {
        $pageNumber = $this->pageNumber($request, $page);
        if ($page !== null && $pageNumber <= 1) {
            return redirect()->away($this->keepQuery($request, url('shop')), 301);
        }
        $shopPage = Page::where('path', 'shop')->where('status', 'published')->first();
        $listing = ProductListing::make()->withRequest($request, Facets::slugs());

        return $this->render($request, $listing, $pageNumber, [
            'context' => 'shop',
            'baseUrl' => url('shop'),
            'heading' => 'Shop',
            'title' => $shopPage?->meta_title ?: Seo::title('Shop'),
            // Rank Math "%title% %page% %sep% %sitename%": "Shop | Page 2 of 10 | {site name}"
            'pagedTitle' => $shopPage?->meta_title ? null : fn (int $n, int $last) => Seo::title('Shop | Page '.$n.' of '.$last),
            'description' => $shopPage?->meta_description ?: theme_config('seo.defaults.shop_description') ?: setting('seo.default_description'),
            'bodyClass' => 'archive post-type-archive post-type-archive-product woocommerce-shop woocommerce woocommerce-page woocommerce-no-js',
            'breadcrumbs' => [['label' => 'Shop', 'url' => url('shop')]],
            'noindex' => (bool) $shopPage?->noindex,
        ]);
    }

    public function category(Request $request, Category $category, int $page = 1)
    {
        $pageNumber = $this->pageNumber($request, $page);
        $listing = ProductListing::make()
            ->inCategories(Categories::descendantIds($category->id))
            ->withRequest($request, Facets::slugs());

        $crumbs = collect(Categories::ancestors($category))->push($category)
            ->map(fn (Category $c) => ['label' => $c->name, 'url' => $c->url])->all();
        $termId = $category->wp_id ?: $category->id;

        return $this->render($request, $listing, $pageNumber, [
            'context' => 'category',
            'category' => $category,
            'baseUrl' => $category->url,
            'heading' => $category->name,
            'title' => $category->meta_title ?: Seo::title($category->name.' Archives'),
            // only the default "%term% Archives %page% %sep% %sitename%" title carries the page number
            'pagedTitle' => ! $category->meta_title || $category->meta_title === Seo::title($category->name.' Archives')
                ? fn (int $n, int $last) => Seo::title($category->name.' Archives | Page '.$n.' of '.$last) : null,
            'description' => $category->meta_description ?: Text::plain($category->description), // Rank Math %term_description% (untruncated)
            'bodyClass' => 'archive tax-product_cat term-'.$category->slug.' term-'.$termId.' woocommerce woocommerce-page woocommerce-no-js',
            'breadcrumbs' => $crumbs,
            'noindex' => false,
            // The legacy archive template left its SEO container (76000c2) empty, so the imported extra content
            // stays hidden unless the store opts in.
            'seoContent' => filter_var(setting('catalog.show_category_extra_content', false), FILTER_VALIDATE_BOOL) ? $category->extra_content : null,
        ]);
    }

    /**
     * Shared archive renderer (also used by SearchController).
     *
     * @param  array{context:string, baseUrl:string, heading:string, title:string, pagedTitle?:?callable, description:?string, bodyClass:string, breadcrumbs:array, noindex:bool, category?:Category, searchTerm?:string, canonical?:string, seoContent?:?string}  $meta
     */
    public function render(Request $request, ProductListing $listing, int $pageNumber, array $meta)
    {
        $base = rtrim($meta['baseUrl'], '/');

        // A price range spanning the whole slider (e.g. the no-JS form submitted untouched) is not a filter.
        $bounds = Facets::priceRange($listing);
        if ($listing->minPrice !== null && $listing->minPrice <= $bounds['min']) {
            $listing->minPrice = null;
        }
        if ($listing->maxPrice !== null && $listing->maxPrice >= $bounds['max']) {
            $listing->maxPrice = null;
        }

        $products = $listing->paginate($pageNumber, $base);
        if ($pageNumber > 1 && $pageNumber > $products->lastPage()) {
            if ($meta['context'] === 'search') {
                abort(404);
            }
            // a shop/category page number past the end (e.g. products sold since the link was made): 301 to the last
            // page that exists, page 1 when the archive is empty - WordPress showed a page too, never a 404
            $last = $products->lastPage();
            $query = http_build_query($this->cleanQuery($request));

            return redirect()->to(($last > 1 ? $base.'/page/'.$last.'/' : $base.'/').($query !== '' ? '?'.$query : ''), 301);
        }
        $products->appends($this->cleanQuery($request));

        $facets = Facets::build($listing);
        $pageUrl = $pageNumber > 1 ? $base.'/page/'.$pageNumber.'/' : $base.'/';
        // the theme may extend core's body classes (ThemeDefinition::bodyClass, key catalog.shop|category|search)
        $bodyClass = app(ThemeManager::class)->bodyClass('catalog.'.$meta['context'], $meta['bodyClass'], $meta)
            .($pageNumber > 1 ? ' paged paged-'.$pageNumber : '');
        $pageLink = fn (int $n) => ($n > 1 ? $base.'/page/'.$n.'/' : $base.'/').($meta['searchTerm'] ?? null ? '?'.http_build_query(['s' => $meta['searchTerm'], 'post_type' => 'product']) : '');

        $data = $meta + [
            'listing' => $listing,
            'products' => $products,
            'facets' => $facets,
            'activeFilters' => Facets::active($listing, $facets),
            'sorts' => ProductListing::SORTS,
            'pageNumber' => $pageNumber,
            'formAction' => $base.'/',
            'searchTerm' => $meta['searchTerm'] ?? null,
            'seoContent' => $meta['seoContent'] ?? null,
            'prevUrl' => $pageNumber > 1 ? $pageLink($pageNumber - 1) : null,
            'nextUrl' => $products->hasMorePages() ? $pageLink($pageNumber + 1) : null,
        ];
        $data['bodyClass'] = $bodyClass;
        $data['seo'] = [
            'title' => $pageNumber > 1 && ! empty($meta['pagedTitle']) ? ($meta['pagedTitle'])($pageNumber, $products->lastPage()) : $meta['title'],
            'description' => $meta['description'] ?? null,
            'canonical' => $meta['canonical'] ?? $pageUrl,
            'type' => 'article',
            'noindex' => $meta['noindex'] ?? false,
            // filtered / sorted variations of an archive are not separate pages
            'robots' => $listing->hasFilters() || $listing->orderby !== '' ? 'noindex, follow' : null,
        ];
        $data['seo'] = array_filter($data['seo'], fn ($v) => $v !== null);

        $ajaxHeader = (string) config('commerce.catalog.ajax_header', 'X-Commerce-Catalog');
        if ($request->header($ajaxHeader) === '1') {
            return response()->json([
                'sidebar' => theme_view('partials.filters', $data)->render(),
                'results' => theme_view('catalog.partials.results', $data)->render(),
                'title' => $data['seo']['title'],
                'total' => $products->total(),
                'filters' => count($data['activeFilters']),
            ])->header('Vary', $ajaxHeader)->header('Cache-Control', 'private, no-store');
        }

        $names = ['shop' => ['catalog.shop', 'catalog.archive'], 'category' => ['catalog.category', 'catalog.archive'],
            'search' => ['search.results', 'catalog.archive']][$meta['context']] ?? ['catalog.archive'];

        return response(theme_view($names, $data))->header('Vary', $ajaxHeader);
    }

    /** Page from the /page/N segment, or a legacy page-builder parameter (config commerce.catalog.legacy_page_params, ?{param}=N). */
    protected function pageNumber(Request $request, $page): int
    {
        $number = (int) ($page ?? 1);
        foreach ((array) config('commerce.catalog.legacy_page_params', []) as $param) {
            if ($number <= 1 && is_numeric($request->query((string) $param))) {
                $number = (int) $request->query((string) $param);
            }
        }

        return max(1, min($number, 100000));
    }

    /** Query parameters kept on pagination links (filters + sort; not the legacy page param). */
    protected function cleanQuery(Request $request): array
    {
        return collect($request->query())
            ->except(array_merge(['page', 'add-to-cart'], (array) config('commerce.catalog.legacy_page_params', [])))
            ->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])
            ->all();
    }

    protected function keepQuery(Request $request, string $url): string
    {
        $query = $request->getQueryString();

        return $query ? $url.'?'.$query : $url;
    }
}
