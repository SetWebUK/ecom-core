<?php

namespace Pine\Commerce\Services\Catalog;

/**
 * Orders the options of one filter group in the shop sidebar (config commerce.catalog.facet_sorter). Options arrive
 * sorted by label (case-insensitive); return them in display order.
 */
interface FacetSorter
{
    /**
     * @param  string  $attribute  attribute slug of the filter group
     * @param  list<array{id:int, slug:string, label:string}>  $options
     * @return list<array{id:int, slug:string, label:string}>
     */
    public function sort(string $attribute, array $options): array;
}
