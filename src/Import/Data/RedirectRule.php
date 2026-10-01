<?php

namespace Pine\Commerce\Import\Data;

/**
 * A redirect rule as the source plugin stores it. `from` is the raw source URL/path (the core normalises it to a
 * lower-case path without slashes); `to` the raw target. Regex and query-string rules are flagged so the core can
 * count/skip them (query-string rules become path-only rules when every variant agrees).
 */
final class RedirectRule
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly int $status = 301,
        public readonly string $source = '',
        public readonly bool $regex = false,
        public readonly int $hits = 0,
        public readonly ?string $lastHitAt = null,
    ) {}

    public function hasQuery(): bool
    {
        return str_contains($this->from, '?');
    }
}
