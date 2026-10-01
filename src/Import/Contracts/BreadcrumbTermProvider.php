<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Data\WpPost;

/**
 * Optional for an SEO adapter: the term its breadcrumb showed for a post, which can differ from the category in the
 * post's URL (WooCommerce/permalink plugin). The importer stores it as the product's breadcrumb category
 * (products.breadcrumb_category_id) when it differs from the URL category. The first provider returning non-null wins.
 */
interface BreadcrumbTermProvider
{
    /**
     * @param  list<array{id:int, name:string, parent:int}>  $terms  the post's terms of $taxonomy
     */
    public function breadcrumbTermId(WpPost $post, array $meta, string $taxonomy, array $terms): ?int;
}
