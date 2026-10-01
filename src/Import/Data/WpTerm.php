<?php

namespace Pine\Commerce\Import\Data;

final class WpTerm
{
    public function __construct(
        public readonly int $id,
        public readonly string $taxonomy,
        public readonly string $name,
        public readonly string $slug,
        public readonly int $parentId = 0,
        public readonly string $description = '',
        public readonly int $order = 0,
        public readonly array $meta = [],
    ) {}

    public static function fromRow(object $t, array $meta = []): self
    {
        return new self((int) $t->term_id, (string) $t->taxonomy, (string) $t->name, (string) $t->slug, (int) ($t->parent ?? 0),
            (string) ($t->description ?? ''), (int) ($meta['order'] ?? $meta['order_'.$t->taxonomy] ?? 0), $meta);
    }
}
