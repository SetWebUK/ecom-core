<?php

namespace Pine\Commerce\Import\WooApi;

/**
 * OAuth 1.0a "one-legged" request signing exactly as WooCommerce verifies it over plain HTTP
 * (WC_REST_Authentication::check_oauth_signature): no token, the consumer secret + "&" as the HMAC key, parameters
 * sorted by name, keys and values RFC 3986 encoded, then each "key=value" encoded again and joined with %26; the
 * oauth_* parameters travel in the query string.
 */
final class OAuth1
{
    /**
     * The query parameters to send: $query + oauth_consumer_key, oauth_nonce, oauth_signature_method, oauth_timestamp,
     * oauth_signature.
     *
     * @param  string  $url  scheme://host[:port]/path – no query string
     */
    public static function sign(string $method, string $url, array $query, string $consumerKey, string $consumerSecret,
        ?int $timestamp = null, ?string $nonce = null, string $signatureMethod = 'HMAC-SHA256'): array
    {
        $params = $query + [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => $nonce ?? bin2hex(random_bytes(16)),
            'oauth_signature_method' => $signatureMethod,
            'oauth_timestamp' => (string) ($timestamp ?? time()),
        ];
        $params['oauth_signature'] = self::signature($method, $url, $params, $consumerSecret);

        return $params;
    }

    public static function signature(string $method, string $url, array $params, string $consumerSecret): string
    {
        unset($params['oauth_signature']);
        $algorithm = strtolower(str_replace('HMAC-', '', (string) ($params['oauth_signature_method'] ?? 'HMAC-SHA256')));

        return base64_encode(hash_hmac($algorithm, self::baseString($method, $url, $params), $consumerSecret.'&', true));
    }

    /** The signature base string: METHOD&encoded-url&encoded-parameters. */
    public static function baseString(string $method, string $url, array $params): string
    {
        uksort($params, 'strcmp');
        $pairs = [];
        foreach (self::flatten($params) as [$key, $value]) {
            $pairs[] = self::encode(self::encode($key).'='.self::encode($value));
        }

        return strtoupper($method).'&'.rawurlencode($url).'&'.implode('%26', $pairs);
    }

    /** @return list<array{0:string, 1:string}> nested arrays as key%5Bsub%5D, like WooCommerce's join_with_equals_sign() */
    private static function flatten(array $params, string $prefix = ''): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';
            if (is_array($value)) {
                $out = array_merge($out, self::flatten($value, $name));
            } else {
                $out[] = [$name, is_bool($value) ? ($value ? '1' : '') : (string) $value];
            }
        }

        return $out;
    }

    public static function encode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }
}
