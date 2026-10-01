<?php

namespace Pine\Commerce\Import\Permalinks;

/**
 * Rebuilds WordPress/WooCommerce permalinks from settings alone (no WordPress code): `permalink_structure` for posts,
 * `woocommerce_permalinks` (product_base incl. %product_cat%, category_base, tag_base) for products and product
 * categories, hierarchical page paths. Pure – every input is passed in, so it is unit-tested without a database.
 * Paths are returned without leading/trailing slash; null = the item has no pretty URL (plain permalinks).
 */
class PermalinkBuilder
{
    /**
     * @param  array<int,array{slug:string,parent:int}>  $categories  product_cat term id => slug/parent
     */
    public function __construct(
        private readonly string $permalinkStructure = '',
        private readonly string $productBase = '',
        private readonly string $categoryBase = '',
        private readonly string $tagBase = '',
        private readonly array $categories = [],
    ) {}

    /** Hierarchical slug path of a product category ("clothing/shirts"), without any base. */
    public function categoryTreePath(int $termId): ?string
    {
        $parts = [];
        $seen = [];
        while ($termId && isset($this->categories[$termId]) && ! isset($seen[$termId])) {
            $seen[$termId] = true;
            array_unshift($parts, $this->categories[$termId]['slug']);
            $termId = (int) $this->categories[$termId]['parent'];
        }

        return $parts ? implode('/', $parts) : null;
    }

    /** Depth of a category (0 = top level). */
    public function depth(int $termId): int
    {
        $path = $this->categoryTreePath($termId);

        return $path === null ? 0 : substr_count($path, '/');
    }

    /** WooCommerce category URL: {category_base|product-category}/{tree path}. */
    public function categoryPath(int $termId): ?string
    {
        $tree = $this->categoryTreePath($termId);
        if ($tree === null) {
            return null;
        }
        $base = trim($this->categoryBase, '/') ?: 'product-category';

        return $base.'/'.$tree;
    }

    public function tagPath(string $slug): string
    {
        return (trim($this->tagBase, '/') ?: 'product-tag').'/'.$slug;
    }

    /**
     * The category WooCommerce puts in %product_cat% (wc_product_post_type_link): the SEO plugin's primary term when
     * given (Rank Math / Yoast hook wc_product_post_type_link_product_cat), else the product's terms sorted by
     * parent DESC, term_id ASC.
     *
     * @param  list<int>  $categoryIds
     */
    public function wooProductCategory(array $categoryIds, ?int $primary = null): ?int
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($primary && in_array($primary, $categoryIds, true)) {
            return $primary;
        }
        $known = array_values(array_filter($categoryIds, fn ($id) => isset($this->categories[$id])));
        if (! $known) {
            return null;
        }
        usort($known, fn ($a, $b) => [$this->categories[$b]['parent'], $a] <=> [$this->categories[$a]['parent'], $b]);

        return $known[0];
    }

    /** Deepest category, lowest id first (the importer's own fallback for the primary category). */
    public function deepestCategory(array $categoryIds): ?int
    {
        $known = array_values(array_filter(array_unique(array_map('intval', $categoryIds)), fn ($id) => isset($this->categories[$id])));
        if (! $known) {
            return null;
        }
        usort($known, fn ($a, $b) => [$this->depth($b), $a] <=> [$this->depth($a), $b]);

        return $known[0];
    }

    /** WooCommerce product URL from product_base ('/product/', '/shop/%product_cat%/' …). */
    public function productPath(string $slug, array $categoryIds = [], ?int $primary = null): string
    {
        $base = trim($this->productBase, '/') ?: 'product';
        if (str_contains($base, '%product_cat%')) {
            $term = $this->wooProductCategory($categoryIds, $primary);
            $base = str_replace('%product_cat%', ($term ? $this->categoryTreePath($term) : null) ?? 'uncategorized', $base);
        }

        return trim($base, '/').'/'.$slug;
    }

    /**
     * Post URL from permalink_structure ('/%postname%/', '/blog/%postname%/', '/%year%/%monthnum%/%postname%/' …).
     *
     * @param  array{slug:string,id:int,date:?string,category?:?string,author?:?string}  $post  date = local post_date
     */
    public function postPath(array $post): ?string
    {
        $structure = trim($this->permalinkStructure);
        if ($structure === '') {
            return null; // plain permalinks: ?p=ID
        }
        $date = $post['date'] ?? null;
        $ts = $date ? strtotime($date.' UTC') : false;
        $tags = [
            '%year%' => $ts ? gmdate('Y', $ts) : '', '%monthnum%' => $ts ? gmdate('m', $ts) : '', '%day%' => $ts ? gmdate('d', $ts) : '',
            '%hour%' => $ts ? gmdate('H', $ts) : '', '%minute%' => $ts ? gmdate('i', $ts) : '', '%second%' => $ts ? gmdate('s', $ts) : '',
            '%postname%' => $post['slug'], '%post_id%' => (string) $post['id'],
            '%category%' => $post['category'] ?? 'uncategorized', '%author%' => $post['author'] ?? '',
        ];
        $path = strtr($structure, $tags);
        $path = preg_replace('#/+#', '/', $path);

        return trim($path, '/');
    }

    /**
     * Page URL: parent slugs + slug; the static front page is ''.
     *
     * @param  array<int,array{slug:string,parent:int}>  $pages
     */
    public static function pagePath(int $id, array $pages, int $frontPage = 0): ?string
    {
        if ($id === $frontPage && $frontPage) {
            return '';
        }
        $parts = [];
        $seen = [];
        while ($id && isset($pages[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($parts, $pages[$id]['slug']);
            $id = (int) $pages[$id]['parent'];
        }

        return $parts ? implode('/', $parts) : null;
    }
}
