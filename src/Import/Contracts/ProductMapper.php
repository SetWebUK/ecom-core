<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Data\WpProduct;
use Pine\Commerce\Import\ImportContext;

/** Adjusts the `products` row built by the core (chained, see Adapter priority) and may supply spec rows. */
interface ProductMapper
{
    /** Return the products row with changes (columns of `products` only). */
    public function mapProduct(array $row, WpProduct $product, ImportContext $ctx): array;

    /** @return list<array{key:string,label:string,value:string,description:?string}>|null spec rows (null = no opinion; like mapProduct the last non-null answer wins) */
    public function specRows(WpProduct $product, ImportContext $ctx): ?array;
}
