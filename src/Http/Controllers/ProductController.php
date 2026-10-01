<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Services\Tax\PriceDisplay;
use Pine\Commerce\Support\Features;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductReview;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\StockNotification;
use Pine\Commerce\Services\Catalog\Categories;
use Pine\Commerce\Services\Catalog\ProductPresenter;
use Pine\Commerce\Services\Catalog\Text;
use Pine\Commerce\Theme\ThemeManager;
use Pine\Commerce\View\Components\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Single product page (theme contract key "product.show"): gallery, buy box with variations, specification,
 * reviews and related products are rendered by the theme; presentation helpers come from commerce_presenter().
 *
 * Reached through ResolveController (canonical /{category-path}/{slug}/ URL only).
 */
class ProductController extends Controller
{
    public const RELATED_LIMIT = 4;

    public function show(Request $request, Product $product)
    {
        $product->loadMissing([
            'images', 'primaryCategory', 'categories', 'attributeValues.attribute', 'specs',
            'variations', 'productAttributes.attribute',
            'reviews' => fn ($q) => $q->where('is_approved', true)->latest(),
        ]);

        $category = $product->primaryCategory ?? $product->categories->first();
        $trail = $this->breadcrumbCategory($product) ?? $category;
        $crumbs = [];
        if ($trail) {
            foreach (array_merge(Categories::ancestors($trail), [$trail]) as $c) {
                $crumbs[] = ['label' => $c->name, 'url' => $c->url];
            }
        }
        $crumbs[] = ['label' => Text::title($product->name), 'url' => $product->url];

        $variationData = $this->variationData($product);
        $image = $product->images->first();
        $title = $product->meta_title ?: Seo::title($product->name);
        // Rank Math: SEO description, else the whole short description, else an excerpt of the description
        $description = $product->meta_description
            ?: (trim(strip_tags((string) $product->short_description)) !== '' ? Text::plain($product->short_description) : Text::metaExcerpt($product->description, $product->focus_keyword));

        return response(theme_view('product.show', [
            'product' => $product,
            'category' => $category,
            'crumbs' => $crumbs,
            'variationData' => $variationData,
            'variationAttributes' => $this->variationAttributes($product, $variationData),
            'related' => $this->related($product),
            'reviews' => $product->reviews,
            'bodyClass' => app(ThemeManager::class)->bodyClass('product.show',
                'wp-singular product-template-default single single-product postid-'.$product->id.' woocommerce woocommerce-page woocommerce-no-js', ['product' => $product]),
            'schema' => $this->schema($product, $category, $description),
            'seo' => [
                'title' => $title,
                'description' => $description,
                'canonical' => $product->url,
                'type' => 'product',
                'image' => $image ? media_url($image->path) : null,
                'modified_time' => optional($product->updated_at)->toIso8601String(),
                'noindex' => $product->status !== 'published',
            ],
        ]));
    }

    /**
     * The breadcrumb's category when it differs from the URL's (products.breadcrumb_category_id, set by the WordPress
     * importer from the SEO plugin's choice); only while that category is still assigned to the product.
     */
    protected function breadcrumbCategory(Product $product): ?\Pine\Commerce\Models\Category
    {
        $id = (int) ($product->breadcrumb_category_id ?? 0);

        return $id > 0 ? $product->categories->firstWhere('id', $id) : null;
    }

    /** [product_review] form: stored unapproved - staff approve it in the back office. */
    public function review(Request $request, Product $product)
    {
        abort_unless($product->status === 'published', 404);
        $this->throttle($request, 'review', 5);

        if (filled($request->input('website'))) { // honeypot
            return $this->done($request, $product, 'Thank you - your review is awaiting approval.');
        }
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'content' => ['required', 'string', 'min:3', 'max:5000'],
        ], [
            'rating.required' => 'Please select a rating.',
        ]);

        $user = $request->user();
        ProductReview::create([
            'product_id' => $product->id,
            'user_id' => $user?->id,
            'name' => strip_tags($data['name']),
            'email' => strtolower($data['email']),
            'rating' => (int) $data['rating'],
            'content' => strip_tags($data['content']),
            'is_approved' => false,
            'is_verified_owner' => $user ? $user->orders()->whereHas('items', fn ($q) => $q->where('product_id', $product->id))->exists() : false,
        ]);

        return $this->done($request, $product, 'Thank you - your review is awaiting approval.', '#reviews');
    }

    /** Back-in-stock alert sign-up (sent by the back office when stock returns). */
    public function notify(Request $request, Product $product)
    {
        abort_unless($product->status === 'published', 404);
        $this->throttle($request, 'notify', 10);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'variation_id' => ['nullable', 'integer'],
        ]);
        $variation = null;
        if (! empty($data['variation_id'])) {
            $variation = ProductVariation::where('product_id', $product->id)->find($data['variation_id']);
        }
        if (! filled($request->input('website'))) { // honeypot
            StockNotification::firstOrCreate([
                'product_id' => $product->id,
                'product_variation_id' => $variation?->id,
                'email' => strtolower($data['email']),
                'notified_at' => null,
            ]);
        }

        return $this->done($request, $product, 'Thanks! We will email you as soon as this product is back in stock.', '#'.theme_config('product.notify_anchor', 'stock-notify'));
    }

    /** Small product summary for a quick-view modal (HTML fragment). */
    public function quickView(Request $request, Product $product)
    {
        abort_unless($product->status === 'published', 404);
        $product->loadMissing(ProductPresenter::CARD_RELATIONS);

        return response(theme_view('product.quick-view', ['product' => $product]))
            ->header('X-Robots-Tag', 'noindex');
    }

    // ------------------------------------------------------------------ helpers

    /** Variations for the JS selector (WooCommerce data-product_variations equivalent). */
    protected function variationData(Product $product): array
    {
        if ($product->type !== 'variable') {
            return [];
        }

        $labels = [];
        foreach ($product->attributeValues as $value) {
            if ($value->attribute) {
                $labels[$value->attribute->slug][$value->slug] = $value->value;
            }
        }

        return commerce_presenter()::activeVariations($product)->map(function (ProductVariation $v) use ($product, $labels) {
            $price = PriceDisplay::shop($v->currentPrice(), $product, $v);
            $regular = $v->regular_price !== null ? PriceDisplay::shop((float) $v->regular_price, $product, $v) : null;
            $maxQty = $v->manage_stock && $v->stock_quantity !== null && ! in_array($product->backorders, ['notify', 'yes'], true) ? max(0, (int) $v->stock_quantity) : null;

            return [
                'variation_id' => $v->id,
                'label' => collect((array) $v->options)->map(fn ($value, $key) => $labels[$key][$value] ?? ucwords(str_replace('-', ' ', (string) $value)))->implode(', '),
                'attributes' => collect((array) $v->options)->mapWithKeys(fn ($value, $key) => [(string) $key => (string) $value])->all(),
                'display_price' => $price,
                'display_regular_price' => $regular ?? $price,
                'price_html' => $price === null ? '' : ($v->isOnSale() ? commerce_presenter()::delIns((float) $regular, $price) : commerce_presenter()::amount($price)).PriceDisplay::suffixHtml($price, $product),
                'is_in_stock' => $v->isInStock() && $maxQty !== 0,
                'max_qty' => $maxQty,
                'sku' => $v->sku ?: $product->sku,
                'image' => $v->image ? media_url($v->image) : null,
                'pay_in_3' => commerce_presenter()::payIn3($price) !== null ? commerce_presenter()::amount(commerce_presenter()::payIn3($price)) : null,
            ];
        })->values()->all();
    }

    /**
     * Attribute selects for variable products, values ordered like the WooCommerce term order.
     *
     * @return array<int, array{slug:string, name:string, options: array<int, array{slug:string,label:string}>}>
     */
    protected function variationAttributes(Product $product, array $variationData): array
    {
        if ($product->type !== 'variable' || ! $variationData) {
            return [];
        }
        $used = [];
        foreach ($variationData as $v) {
            foreach ($v['attributes'] as $slug => $value) {
                $used[$slug][$value] = true;
            }
        }
        $values = $product->attributeValues->filter(fn ($v) => $v->attribute)
            ->sortBy([fn ($a, $b) => $a->sort_order <=> $b->sort_order, fn ($a, $b) => strnatcasecmp($a->value, $b->value)]);
        $attributes = $product->productAttributes->filter(fn ($pa) => $pa->is_variation && $pa->attribute)->sortBy('position');
        $slugs = $attributes->map(fn ($pa) => $pa->attribute->slug)->values()->all() ?: array_keys($used);

        $out = [];
        foreach ($slugs as $slug) {
            if (! isset($used[$slug])) {
                continue;
            }
            $options = $values->filter(fn ($v) => $v->attribute->slug === $slug && isset($used[$slug][$v->slug]))
                ->map(fn ($v) => ['slug' => $v->slug, 'label' => $v->value])->values()->all();
            foreach (array_keys($used[$slug]) as $valueSlug) { // values without a term row
                if (! collect($options)->contains('slug', $valueSlug)) {
                    $options[] = ['slug' => $valueSlug, 'label' => ucwords(str_replace('-', ' ', $valueSlug))];
                }
            }
            $attribute = $attributes->first(fn ($pa) => $pa->attribute->slug === $slug)?->attribute
                ?? $product->attributeValues->first(fn ($v) => $v->attribute->slug === $slug)?->attribute;
            $out[] = ['slug' => $slug, 'name' => $attribute?->name ?? ucwords(str_replace('-', ' ', $slug)), 'options' => $options];
        }

        return $out;
    }

    /**
     * "You may also like": upsells/cross-sells from related_products, topped up with in-stock products from the
     * same categories, newest first (wc_get_related_products), in stock only.
     */
    protected function related(Product $product): Collection
    {
        $limit = self::RELATED_LIMIT;
        $query = fn () => Product::query()->published()->inStock()->whereKeyNot($product->id)->with(ProductPresenter::CARD_RELATIONS);

        $picked = $query()->whereIn('id', $product->related()->pluck('related_id'))->get();
        if ($picked->count() < $limit) {
            $categoryIds = $product->categories->pluck('id')->all();
            if ($categoryIds) {
                $extra = $query()
                    ->whereNotIn('id', $picked->pluck('id'))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('category_product')
                        ->whereColumn('category_product.product_id', 'products.id')
                        ->whereIn('category_product.category_id', $categoryIds))
                    ->orderByDesc('published_at')->orderByDesc('id')
                    ->limit($limit - $picked->count())
                    ->get();
                $picked = $picked->concat($extra);
            }
        }

        return $picked->take($limit)->values();
    }

    /** Product JSON-LD (Rank Math WooCommerce schema equivalent). */
    protected function schema(Product $product, $category, string $description): array
    {
        $price = commerce_presenter()::price($product);
        $inStock = commerce_presenter()::inStock($product);
        $brand = commerce_presenter()::brandName($product);
        $reviews = $product->reviews;
        $condition = commerce_presenter()::itemCondition($product);

        $categoryPath = $category ? collect(Categories::ancestors($category))->push($category)->pluck('name')->implode(' > ') : null;

        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $product->url.'#richSnippet',
            'name' => $product->meta_title ?: $product->name,
            'description' => $description ?: null,
            'sku' => $product->sku ?: null,
            'mpn' => $product->mpn ?: null,
            'gtin' => $product->gtin ?: null,
            'category' => $categoryPath,
            'brand' => $brand ? ['@type' => 'Brand', 'name' => $brand] : null,
            'image' => $product->images->map(fn ($img) => media_url($img->path))->values()->all() ?: null,
            'url' => $product->url,
            'offers' => $price !== null ? array_filter([
                '@type' => 'Offer',
                'price' => number_format($price, 2, '.', ''),
                'priceCurrency' => 'GBP',
                'priceValidUntil' => now()->addYear()->endOfYear()->toDateString(),
                'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => $condition,
                'url' => $product->url,
                'seller' => ['@type' => 'Organization', 'name' => setting('store.name', config('app.name')), 'url' => url('/').'/'],
            ]) : null,
        ], fn ($v) => $v !== null && $v !== []);

        if ($reviews->isNotEmpty() && Features::enabled('reviews')) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round($reviews->avg('rating'), 2),
                'reviewCount' => $reviews->count(),
            ];
            $schema['review'] = $reviews->take(5)->map(fn (ProductReview $r) => [
                '@type' => 'Review',
                'author' => ['@type' => 'Person', 'name' => $r->name],
                'datePublished' => optional($r->created_at)->toDateString(),
                'reviewBody' => (string) $r->content,
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) $r->rating, 'bestRating' => 5],
            ])->values()->all();
        }

        return $schema;
    }

    protected function throttle(Request $request, string $action, int $perHour): void
    {
        $key = 'product-'.$action.':'.sha1((string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts - please try again later.']);
        }
        RateLimiter::hit($key, 3600);
    }

    protected function done(Request $request, Product $product, string $message, string $anchor = '')
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return redirect()->away($product->url.$anchor)->with('product_notice', $message);
    }
}
