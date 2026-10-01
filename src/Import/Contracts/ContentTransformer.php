<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Data\ContentItem;

/** Transforms raw post_content before it is cleaned (all transformers run, highest priority first). */
interface ContentTransformer
{
    public function transform(string $html, ContentItem $item): string;
}
