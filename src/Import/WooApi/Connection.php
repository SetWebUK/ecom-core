<?php

namespace Pine\Commerce\Import\WooApi;

use InvalidArgumentException;

/**
 * Where and how to reach a WooCommerce shop's REST API: the site address, the REST API key (consumer key + secret
 * from WooCommerce › Settings › Advanced › REST API – "Read" permission is enough), an optional WordPress
 * application password for private pages/posts, TLS verification and the authentication mode:
 *
 *   auto   https → HTTP Basic auth with key/secret; plain http → OAuth 1.0a (WooCommerce refuses Basic over http)
 *   basic  always Basic auth (https only)
 *   query  consumer_key/consumer_secret in the query string – for hosts that strip the Authorization header (https only)
 *   oauth  always OAuth 1.0a signatures
 *
 * `store` mode needs no key at all: the public WooCommerce Store API (wc/store/v1) – catalogue only.
 */
final class Connection
{
    public const AUTH_MODES = ['auto', 'basic', 'query', 'oauth'];

    public function __construct(
        public readonly string $url,
        public readonly string $key = '',
        public readonly string $secret = '',
        public readonly string $wpUser = '',
        public readonly string $wpPassword = '',
        public readonly bool $verifyTls = true,
        public readonly string $auth = 'auto',
        public readonly bool $store = false,
    ) {
        if (! in_array($auth, self::AUTH_MODES, true)) {
            throw new InvalidArgumentException("Unknown authentication mode '$auth' (".implode(', ', self::AUTH_MODES).').');
        }
    }

    public static function make(array $c): self
    {
        return new self(self::normaliseUrl((string) ($c['url'] ?? '')), trim((string) ($c['key'] ?? '')), trim((string) ($c['secret'] ?? '')),
            trim((string) ($c['wp_user'] ?? '')), preg_replace('/\s+/', '', (string) ($c['wp_password'] ?? '')) ?? '',
            (bool) ($c['verify_tls'] ?? true), (string) (($c['auth'] ?? '') ?: 'auto'), (bool) ($c['store'] ?? false));
    }

    /** "shop.example.com/" → "https://shop.example.com"; keeps a sub-directory install's path. */
    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }
        $p = parse_url($url);
        if (! $p || empty($p['host'])) {
            throw new InvalidArgumentException('That is not a valid web address.');
        }
        $path = rtrim((string) ($p['path'] ?? ''), '/');
        $path = preg_replace('#/(wp-json|wp-admin)(/.*)?$#', '', $path); // pasted an API or admin URL

        return strtolower($p['scheme']).'://'.strtolower($p['host']).(isset($p['port']) ? ':'.$p['port'] : '').$path;
    }

    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }

    public function isHttps(): bool
    {
        return str_starts_with($this->url, 'https://');
    }

    /** The import_source of rows from this shop: "woo:{host}{/path}" (max 100 chars). */
    public function sourceKey(): string
    {
        $p = parse_url($this->url);
        $key = 'woo:'.preg_replace('/^www\./', '', strtolower((string) ($p['host'] ?? ''))).(isset($p['port']) ? ':'.$p['port'] : '').rtrim((string) ($p['path'] ?? ''), '/');

        return strlen($key) > 100 ? 'woo:'.substr(sha1($key), 0, 40) : $key;
    }

    /** Authentication used for wc/v3 requests: basic | query | oauth | none. */
    public function wcAuth(): string
    {
        if ($this->store || $this->key === '' || $this->secret === '') {
            return 'none';
        }

        return $this->auth === 'auto' ? ($this->isHttps() ? 'basic' : 'oauth') : $this->auth;
    }

    public function hasKeys(): bool
    {
        return $this->key !== '' && $this->secret !== '';
    }

    public function hasWpPassword(): bool
    {
        return $this->wpUser !== '' && $this->wpPassword !== '';
    }

    /** Every secret value, for masking in logs and messages. @return list<string> */
    public function secrets(): array
    {
        return array_values(array_filter([$this->key, $this->secret, $this->wpPassword], fn ($v) => strlen($v) >= 4));
    }

    /** Replace secrets in a text with "***". */
    public function mask(string $text): string
    {
        foreach ($this->secrets() as $secret) {
            $text = str_replace([$secret, rawurlencode($secret)], '***', $text);
        }

        return preg_replace('/(consumer_(?:key|secret)|oauth_signature|oauth_consumer_key)=[^&\s"]+/', '$1=***', $text) ?? $text;
    }

    /** Safe description for logs: "https://shop.example.com (key ck_…1234, auth basic)". */
    public function describe(): string
    {
        if ($this->store) {
            return $this->url.' (public Store API, no key)';
        }

        return $this->url.' (key '.($this->key !== '' ? substr($this->key, 0, 3).'…'.substr($this->key, -4) : 'none').', auth '.$this->wcAuth().')';
    }
}
