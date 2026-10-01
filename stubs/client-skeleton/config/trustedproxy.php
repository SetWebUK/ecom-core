<?php

/*
| Proxies whose X-Forwarded-* headers are trusted. Empty by default: on a plain LiteSpeed/Apache host nothing sits in
| front of PHP, and trusting every caller would let anyone spoof their IP past the login/form rate limits. Behind a
| CDN or load balancer (Cloudflare, AWS ALB …) set TRUSTED_PROXIES to its addresses/CIDRs, comma separated.
*/

return [
    'proxies' => env('TRUSTED_PROXIES'),
];
