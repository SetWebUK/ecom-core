<?php

namespace Pine\Commerce\Services\Catalog;

use Pine\Commerce\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Product listing query for the shop page, category archives and search results
 * (commerce.catalog.per_page per page).
 *
 * Query string (WooCommerce layered-nav style, so links are shareable and work without JavaScript):
 *   min_price=100&max_price=500          price range (current price; variable products use their lowest price)
 *   filter_size=small,medium             attribute values (slugs) - OR within an attribute, AND across attributes
 *   filter_condition=used-like-new       (radio - one value)
 *   orderby=popularity|date|price|price-desc|title|title-desc|rating|menu_order
 * Arrays (filter_size[]=small, as posted by the no-JS form) are accepted too.
 */
class ProductListing
{
    public const PER_PAGE = 30;

    /**
     * orderby value => label, in the order of the legacy JetSmartFilters sorting widget (44dc44f), which offered
     * exactly these. "" = default ordering (menu order, then product ID like the live loop grid).
     */
    public const SORTS = [
        '' => 'Sort...',
        'title' => 'By title from lowest to highest',
        'title-desc' => 'By title from highest to lowest',
        'date-asc' => 'By date from lowest to highest',
        'date' => 'By date from highest to lowest',
    ];

    /** Accepted but not listed: WooCommerce defaults / legacy values. */
    public const EXTRA_SORTS = ['popularity', 'price', 'price-desc', 'menu_order', 'rating', 'relevance'];

    public ?array $categoryIds = null;

    public ?string $search = null;

    /** @var array<string, string[]> attribute slug => value slugs */
    public array $attributes = [];

    public ?float $minPrice = null;

    public ?float $maxPrice = null;

    public string $orderby = '';

    public static function make(): static
    {
        return new static;
    }

    public function inCategories(array $ids): static
    {
        $this->categoryIds = array_values(array_unique(array_map('intval', $ids)));

        return $this;
    }

    public function searching(?string $term): static
    {
        $term = trim(preg_replace('/\s+/u', ' ', (string) $term));
        $this->search = $term === '' ? null : mb_substr($term, 0, 100);

        return $this;
    }

    /** Read filters + sort from the request. Only attributes in $allowed (slugs) are honoured. */
    public function withRequest(Request $request, array $allowed): static
    {
        foreach ($allowed as $slug) {
            $raw = $request->query('filter_'.$slug);
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }
            $values = is_array($raw) ? $raw : explode(',', (string) $raw);
            $values = array_values(array_unique(array_filter(array_map(
                fn ($v) => is_scalar($v) ? substr(preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $v)), 0, 100) : '',
                $values
            ), fn ($v) => $v !== '' && $v !== 'all')));
            if ($values) {
                $this->attributes[$slug] = array_slice($values, 0, 50);
            }
        }
        // "1,339" / "£159" as typed into (or formatted by) the range inputs
        $min = is_string($request->query('min_price')) ? str_replace([',', '£', ' '], '', $request->query('min_price')) : null;
        $max = is_string($request->query('max_price')) ? str_replace([',', '£', ' '], '', $request->query('max_price')) : null;
        $this->minPrice = is_numeric($min) && (float) $min >= 0 ? (float) $min : null;
        $this->maxPrice = is_numeric($max) && (float) $max >= 0 ? (float) $max : null;
        if ($this->minPrice !== null && $this->maxPrice !== null && $this->minPrice > $this->maxPrice) {
            [$this->minPrice, $this->maxPrice] = [$this->maxPrice, $this->minPrice];
        }
        $orderby = is_string($request->query('orderby')) ? $request->query('orderby') : '';
        $this->orderby = array_key_exists($orderby, self::SORTS) || in_array($orderby, self::EXTRA_SORTS, true) ? $orderby : '';

        return $this;
    }

    public function hasFilters(): bool
    {
        return $this->attributes !== [] || $this->minPrice !== null || $this->maxPrice !== null;
    }

    /** Whether out-of-stock products are hidden from listings (WooCommerce "Hide out of stock items", on for the live store). */
    public static function hidesOutOfStock(): bool
    {
        return filter_var(setting('catalog.hide_out_of_stock', true), FILTER_VALIDATE_BOOL);
    }

    // ------------------------------------------------------------------ queries

    /** Published products in scope (categories / search), stock visibility applied, no filters. */
    public function scopeQuery(bool $respectStock = true): Builder
    {
        $query = Product::query()->published();
        if ($respectStock && static::hidesOutOfStock()) {
            $query->inStock();
        }
        if ($this->categoryIds !== null) {
            $ids = $this->categoryIds ?: [0];
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('category_product')
                ->whereColumn('category_product.product_id', 'products.id')
                ->whereIn('category_product.category_id', $ids));
        }
        if ($this->search !== null) {
            foreach ($this->terms() as $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('products.name', 'like', $like)
                    ->orWhere('products.short_description', 'like', $like)
                    ->orWhere('products.description', 'like', $like)
                    ->orWhere('products.sku', 'like', $like));
            }
        }

        return $query;
    }

    /** Scope + price + attribute filters (optionally ignoring one attribute - for its own facet counts). */
    public function filteredQuery(?string $exceptAttribute = null, bool $withPrice = true): Builder
    {
        $query = $this->scopeQuery();
        if ($withPrice && $this->minPrice !== null) {
            $query->where('products.price', '>=', $this->minPrice);
        }
        if ($withPrice && $this->maxPrice !== null) {
            $query->where('products.price', '<=', $this->maxPrice);
        }
        foreach ($this->attributes as $slug => $values) {
            if ($slug === $exceptAttribute) {
                continue;
            }
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('attribute_value_product as avp')
                ->join('attribute_values as av', 'av.id', '=', 'avp.attribute_value_id')
                ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
                ->whereColumn('avp.product_id', 'products.id')
                ->where('a.slug', $slug)
                ->whereIn('av.slug', $values));
        }

        return $query;
    }

    public function applySort(Builder $query): Builder
    {
        $orderby = $this->orderby;
        if (($orderby === '' || $orderby === 'relevance') && $this->search !== null) {
            // WordPress search relevance: whole phrase in the title, then every word in the title, then newest first
            $phrase = '%'.addcslashes($this->search, '%_\\').'%';
            $name = $query->getQuery()->getGrammar()->wrap('products.name'); // grammar-wrapped: honours a table prefix
            $query->orderByRaw("CASE WHEN {$name} LIKE ? THEN 0 ELSE 1 END", [$phrase]);
            $terms = $this->terms();
            if (count($terms) > 1) {
                $sql = implode(' AND ', array_fill(0, count($terms), "{$name} LIKE ?"));
                $query->orderByRaw("CASE WHEN {$sql} THEN 0 ELSE 1 END", array_map(fn ($t) => '%'.addcslashes($t, '%_\\').'%', $terms));
            }

            return $query->orderByDesc('products.published_at')->orderByDesc('products.id');
        }

        return match ($orderby) {
            'popularity' => $query->orderByDesc('products.total_sales')->orderByDesc('products.id'),
            'rating' => $query->orderByDesc('products.average_rating')->orderByDesc('products.review_count')->orderByDesc('products.id'),
            'date' => $query->orderByDesc('products.published_at')->orderByDesc('products.id'),
            'date-asc' => $query->orderBy('products.published_at')->orderBy('products.id'),
            'price' => $query->orderByRaw($query->getQuery()->getGrammar()->wrap('products.price').' IS NULL')->orderBy('products.price')->orderBy('products.id'),
            'price-desc' => $query->orderByDesc('products.price')->orderByDesc('products.id'),
            'title' => $query->orderBy('products.name')->orderBy('products.id'),
            'title-desc' => $query->orderByDesc('products.name')->orderByDesc('products.id'),
            // live loop grid: menu order, then post ID ascending (the oldest listing first)
            default => $query->orderBy('products.sort_order')->orderBy('products.id'),
        };
    }

    public function paginate(int $page, string $path): LengthAwarePaginator
    {
        $query = $this->applySort($this->filteredQuery())->with(ProductPresenter::CARD_RELATIONS);

        return $query->paginate(self::PER_PAGE, ['products.*'], 'page', max(1, $page))->withPath($path);
    }

    /** @return string[] search words (max 10) */
    public function terms(): array
    {
        if ($this->search === null) {
            return [];
        }
        $words = preg_split('/\s+/u', $this->search, -1, PREG_SPLIT_NO_EMPTY);

        return array_slice(array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) > 0))), 0, 10);
    }

    /** Stable key describing scope + filters (facet cache). */
    public function cacheKey(string $prefix, ?string $except = null, bool $withPrice = true): string
    {
        $attributes = $this->attributes;
        unset($attributes[$except]);
        ksort($attributes);

        return $prefix.':'.md5(json_encode([
            $this->categoryIds, $this->search, $attributes, $withPrice ? [$this->minPrice, $this->maxPrice] : null, static::hidesOutOfStock(),
        ]));
    }
}
