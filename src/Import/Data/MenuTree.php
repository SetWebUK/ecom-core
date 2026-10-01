<?php

namespace Pine\Commerce\Import\Data;

final class MenuTree
{
    /** @param list<MenuNode> $items */
    public function __construct(
        public readonly string $location,
        public readonly string $name,
        public array $items = [],
    ) {}
}
