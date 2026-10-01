<?php

namespace Pine\Commerce\Import\Data;

/** A page/post/product/term body being imported. `path` is the source site's URL path (no slashes, '' = home). */
final class ContentItem
{
    public function __construct(
        public readonly string $kind,
        public readonly int $wpId,
        public readonly string $path,
        public readonly string $html,
        public readonly array $meta = [],
    ) {}
}
