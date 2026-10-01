<?php

namespace Pine\Commerce\Services\Catalog;

use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sidebar filters of the product archive: price range + one group per filterable attribute.
 *
 * Groups come from config commerce.catalog.filters (attribute slug => [title, type radio|checkbox|image, hide_empty],
 * in sidebar order); an empty list = every attribute marked "filterable" in the admin, as checkboxes. Options are the
 * attribute values used by at least one published product, sorted by name and then by the configured facet sorter
 * (commerce.catalog.facet_sorter, see FacetSorter). Counts are "other changed": products in scope matching every
 * *other* active filter. Groups with hide_empty drop options with no matches. An "image" group shows the brand logos
 * of the presenter (theme config product.brand_logos). The condition / brand attribute groups only show while the
 * product_condition / product_brand switches are on.
 */
class Facets
{
    public const TTL = 300; // seconds - counts are cached briefly

    /**
     * Configured filter groups: attribute slug => [title, type, hide_empty] in sidebar order.
     *
     * @return array<string, array{title:string, type:string, hide_empty:bool}>
     */
    public static function filters(): array
    {
        static $memo = null;
        $configured = (array) config('commerce.catalog.filters', []);
        $hidden = static::switchedOffSlugs();
        $key = md5(json_encode([$configured, $hidden]));
        if ($memo !== null && $memo[0] === $key) {
            return $memo[1];
        }
        $filters = [];
        if ($configured) {
            foreach ($configured as $slug => $filter) {
                $filters[(string) $slug] = ['title' => (string) ($filter['title'] ?? Str::headline((string) $slug)), 'type' => (string) ($filter['type'] ?? 'checkbox'), 'hide_empty' => (bool) ($filter['hide_empty'] ?? false)];
            }
        } else {
            try {
                foreach (Attribute::query()->where('is_filterable', true)->orderBy('name')->get(['slug', 'name']) as $attribute) {
                    $filters[$attribute->slug] = ['title' => $attribute->name, 'type' => 'checkbox', 'hide_empty' => false];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $filters = array_diff_key($filters, array_flip($hidden));
        $memo = [$key, $filters];

        return $filters;
    }

    /**
     * Attribute slugs whose facet belongs to a switched-off feature: the condition attribute
     * (commerce.catalog.condition_attribute) without product_condition, the brand attribute
     * (commerce.catalog.brand_attribute) without product_brand.
     *
     * @return list<string>
     */
    public static function switchedOffSlugs(): array
    {
        $slugs = [];
        if (! commerce_feature('product_condition')) {
            $slugs[] = commerce_presenter()::conditionAttribute();
        }
        if (! commerce_feature('product_brand')) {
            $slugs[] = commerce_presenter()::brandAttribute();
        }

        return $slugs;
    }

    /** The configured facet sorter (commerce.catalog.facet_sorter). */
    public static function sorter(): FacetSorter
    {
        $class = config('commerce.catalog.facet_sorter');

        return is_string($class) && is_a($class, FacetSorter::class, true) ? app($class) : app(DefaultFacetSorter::class);
    }

    /** @return string[] attribute slugs that can be filtered on */
    public static function slugs(): array
    {
        return array_keys(static::attributes());
    }

    /** @return array<string, Attribute> filterable attributes present in the database, keyed by slug */
    public static function attributes(): array
    {
        static $memo = null;
        $filters = static::filters();
        $key = implode(',', array_keys($filters)).'@'.config('database.default'); // memo per filter set / connection
        if ($memo !== null && $memo[0] === $key) {
            return $memo[1];
        }
        $attributes = Attribute::query()->whereIn('slug', array_keys($filters))->where('is_filterable', true)->get()->keyBy('slug');
        $found = [];
        foreach (array_keys($filters) as $slug) {
            if ($attributes->has($slug)) {
                $found[$slug] = $attributes[$slug];
            }
        }
        $memo = [$key, $found];

        return $found;
    }

    /**
     * Sidebar data for a listing.
     *
     * @return array{price: array{min:int,max:int,from:?int,to:?int}, groups: array<int, array>}
     */
    public static function build(ProductListing $listing): array
    {
        $groups = [];
        $options = static::options();
        $filters = static::filters();
        foreach (static::attributes() as $slug => $attribute) {
            $config = $filters[$slug];
            $counts = static::counts($listing, $attribute->id, $slug);
            $selected = $listing->attributes[$slug] ?? [];
            $items = [];
            foreach ($options[$attribute->id] ?? [] as $option) {
                $count = (int) ($counts[$option['id']] ?? 0);
                $isSelected = in_array($option['slug'], $selected, true);
                if ($config['hide_empty'] && $count === 0 && ! $isSelected) {
                    continue;
                }
                $items[] = $option + ['count' => $count, 'selected' => $isSelected, 'image' => $config['type'] === 'image' ? static::brandImage($option['label']) : null];
            }
            if (! $items && $config['type'] !== 'radio') {
                continue;
            }
            $groups[] = [
                'slug' => $slug,
                'param' => 'filter_'.$slug,
                'title' => $config['title'],
                'type' => $config['type'],
                'items' => $items,
                'selected' => $selected,
                'active' => $selected !== [],
            ];
        }

        return ['price' => static::priceRange($listing), 'groups' => $groups];
    }

    /** All used values per attribute id: [ ['id','slug','label'] ] in display order. Cached. */
    public static function options(): array
    {
        return Cache::remember('catalog.facet-options', self::TTL, function () {
            $rows = DB::table('attribute_values as av')
                ->join('attribute_value_product as avp', 'avp.attribute_value_id', '=', 'av.id')
                ->join('products as p', 'p.id', '=', 'avp.product_id')
                ->where('p.status', 'published')->whereNull('p.deleted_at')
                ->groupBy('av.id', 'av.attribute_id', 'av.slug', 'av.value')
                ->select('av.id', 'av.attribute_id', 'av.slug', 'av.value')
                ->get();
            $slugs = Attribute::query()->pluck('slug', 'id');
            $sorter = static::sorter();
            $out = [];
            foreach ($rows->groupBy('attribute_id') as $attributeId => $values) {
                $list = $values->map(fn ($v) => ['id' => (int) $v->id, 'slug' => $v->slug, 'label' => $v->value])->all();
                usort($list, fn ($a, $b) => strcasecmp($a['label'], $b['label']) ?: strcmp($a['label'], $b['label']));
                $out[$attributeId] = array_values($sorter->sort((string) ($slugs[$attributeId] ?? ''), $list));
            }

            return $out;
        });
    }

    /** value id => matching product count for one attribute. */
    public static function counts(ProductListing $listing, int $attributeId, string $slug): array
    {
        return Cache::remember($listing->cacheKey('catalog.facet-counts.'.$slug, $slug), self::TTL, function () use ($listing, $attributeId, $slug) {
            return $listing->filteredQuery($slug)
                ->join('attribute_value_product as favp', 'favp.product_id', '=', 'products.id')
                ->join('attribute_values as fav', 'fav.id', '=', 'favp.attribute_value_id')
                ->where('fav.attribute_id', $attributeId)
                ->groupBy('fav.id')
                ->toBase()->selectRaw(self::col('fav.id').' as value_id, COUNT(DISTINCT '.self::col('products.id').') as aggregate')
                ->pluck('aggregate', 'value_id')->map(fn ($c) => (int) $c)->all();
        });
    }

    /** A qualified column for raw SQL, wrapped by the grammar so a connection table prefix (scratch: zz_) applies. */
    private static function col(string $column): string
    {
        return (new Product)->getConnection()->getQueryGrammar()->wrap($column);
    }

    /** Slider bounds: lowest/highest price of every published product in scope (whole pounds), plus the active range. */
    public static function priceRange(ProductListing $listing): array
    {
        $bounds = Cache::remember('catalog.price-range:'.md5(json_encode([$listing->categoryIds, $listing->search])), self::TTL, function () use ($listing) {
            $price = self::col('products.price');
            $row = $listing->scopeQuery(false)->toBase()->selectRaw("MIN({$price}) as min_price, MAX({$price}) as max_price")->first();

            return [(int) floor((float) ($row->min_price ?? 0)), (int) ceil((float) ($row->max_price ?? 0))];
        });
        [$min, $max] = $bounds;

        return [
            'min' => $min,
            'max' => max($max, $min),
            'from' => $listing->minPrice !== null ? (int) floor($listing->minPrice) : null,
            'to' => $listing->maxPrice !== null ? (int) ceil($listing->maxPrice) : null,
        ];
    }

    /** Logo shown next to a brand option (theme config product.brand_logos through the presenter). */
    public static function brandImage(string $label): ?string
    {
        foreach (commerce_presenter()::brandLogos() as $brand => $path) {
            if (strcasecmp(trim($label), (string) $brand) === 0) {
                return media_url($path);
            }
        }

        return null;
    }

    /** Human list of the active filters (JetSmartFilters "active filters" widget). */
    public static function active(ProductListing $listing, array $facets): array
    {
        $active = [];
        $price = $facets['price'];
        if ($listing->minPrice !== null || $listing->maxPrice !== null) {
            $from = $price['from'] ?? $price['min'];
            $to = $price['to'] ?? $price['max'];
            $active[] = ['label' => 'Price', 'value' => '£'.number_format($from).' — £'.number_format($to), 'remove' => ['min_price' => null, 'max_price' => null]];
        }
        foreach ($facets['groups'] as $group) {
            foreach ($group['items'] as $item) {
                if ($item['selected']) {
                    $remaining = array_values(array_diff($group['selected'], [$item['slug']]));
                    $active[] = ['label' => $group['title'], 'value' => $item['label'], 'remove' => [$group['param'] => $remaining ? implode(',', $remaining) : null]];
                }
            }
        }

        return $active;
    }
}
