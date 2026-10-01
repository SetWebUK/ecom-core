<?php

namespace Pine\Commerce\Import\Data;

final class SeoData
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $focusKeyword = null,
        public readonly bool $noindex = false,
        public readonly ?string $canonical = null,
    ) {}
}
