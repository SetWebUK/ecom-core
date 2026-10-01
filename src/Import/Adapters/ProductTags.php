<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Steps\TaxonomyAttributeStep;

/** Product tags (product_tag): the schema has no tag table, so tags become a non-filterable "tags" attribute. */
class ProductTags extends AbstractAdapter
{
    public function key(): string
    {
        return 'product-tags';
    }

    public function label(): string
    {
        return 'Product tags';
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $wp->countTerms('product_tag') > 0;
    }

    public function steps(): array
    {
        return [new TaxonomyAttributeStep('catalog.tags', 'product_tag', 'tags', 'Tags')];
    }
}
