<?php

namespace Pine\Commerce\Import\Contracts;

/**
 * Real URLs of the source site. Maps are merged per id: the highest-priority provider that knows an id wins, the
 * core PermalinkBuilder (permalink_structure + woocommerce_permalinks) fills the rest.
 */
interface PermalinkProvider
{
    /** @return array{products?: array<int,string>, categories?: array<int,string>, pages?: array<int,string>, posts?: array<int,string>} wp id => path without leading/trailing slash */
    public function permalinks(): array;
}
