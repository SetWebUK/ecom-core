<?php

namespace Pine\Commerce\Import\WooApi;

use RuntimeException;

/**
 * A failed WooCommerce/WordPress REST API call. `reason`: auth (401 – wrong key/secret or the host strips the
 * Authorization header), forbidden (403 – the key lacks read permission), not_found (404 / rest_no_route – endpoint
 * or plugin missing), rate_limited, server, network, blocked (SSRF guard), redirect, invalid (not JSON).
 * Messages never contain the consumer key, secret or application password.
 */
class WooApiException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'error', public readonly ?int $status = null,
        public readonly ?string $endpoint = null, public readonly ?string $apiCode = null)
    {
        parent::__construct($message);
    }
}
