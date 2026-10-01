<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Data\WpTerm;
use Pine\Commerce\Import\ImportContext;

/** Adjusts the `categories` row built by the core (chained). */
interface TermMapper
{
    public function mapCategory(array $row, WpTerm $term, array $termMeta, ImportContext $ctx): array;
}
