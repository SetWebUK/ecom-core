<?php

namespace Pine\Commerce\Import\Data;

/** A menu item. `sortOrder` null = position among its siblings. Items are written depth-first (parent, then children). */
final class MenuNode
{
    /** @param list<MenuNode> $children */
    public function __construct(
        public string $label,
        public ?string $url = null,
        public ?string $badge = null,
        public ?string $icon = null,
        public ?string $cssClass = null,
        public bool $newTab = false,
        public array $children = [],
        public ?int $sortOrder = null,
    ) {}
}
