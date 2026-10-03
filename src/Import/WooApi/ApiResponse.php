<?php

namespace Pine\Commerce\Import\WooApi;

/** One decoded REST API response: JSON body + the WordPress pagination headers. */
final class ApiResponse
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $json,
        public readonly ?int $total = null,
        public readonly ?int $totalPages = null,
        public readonly string $endpoint = '',
    ) {}

    /** @return list<array> the body as a list of items ([] when it is not a list) */
    public function items(): array
    {
        return is_array($this->json) && array_is_list($this->json) ? array_values(array_filter($this->json, 'is_array')) : [];
    }
}
