<?php

namespace Pine\Commerce\Services\Catalog;

/** Natural order ("2GB" before "16GB", "Size 9" before "Size 10") for every filter group. */
class DefaultFacetSorter implements FacetSorter
{
    public function sort(string $attribute, array $options): array
    {
        usort($options, fn ($a, $b) => strnatcasecmp($a['label'], $b['label'])); // stable (PHP 8)

        return $options;
    }
}
