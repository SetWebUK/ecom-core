<?php

namespace Pine\Commerce\Import\WooApi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP client of the WooCommerce API importer (Laravel's Http client, so tests use Http::fake()):
 *
 *  - wc/v3 with the shop's API key (Connection::wcAuth(): Basic / query string / OAuth 1.0a), wp/v2 anonymously or
 *    with a WordPress application password, wc/store/v1 anonymously;
 *  - "pretty" /wp-json/ URLs, or ?rest_route= when the shop has no pretty permalinks (detected once);
 *  - SSRF guard on every request (UrlGuard) with the connection pinned to the checked address, no redirects
 *    followed for API calls (a redirect is reported with the address to use instead);
 *  - polite: a pause between requests (commerce.woo_api.delay_ms), per-request timeouts, retries with exponential
 *    backoff on 429/5xx/network errors honouring Retry-After;
 *  - TLS verification on unless the connection switches it off (staging shops with self-signed certificates).
 *
 * Secrets never appear in exceptions or the request log (Connection::mask()).
 */
class Client
{
    /** wp-json | rest_route */
    public string $routeStyle = 'wp-json';

    public int $requests = 0;

    /** @var (callable(float):void) seconds => sleep (tests replace it) */
    protected $sleeper;

    /** @var (callable(string):void)|null */
    protected $logger;

    protected float $lastRequestAt = 0.0;

    public function __construct(public readonly Connection $connection, ?callable $sleeper = null, ?callable $logger = null)
    {
        $this->sleeper = $sleeper ?? fn (float $seconds) => $seconds > 0 ? usleep((int) round($seconds * 1_000_000)) : null;
        $this->logger = $logger;
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        return config('commerce.woo_api.'.$key, $default);
    }

    /** GET a route ('products', 'orders/12/refunds') of a namespace ('wc/v3', 'wp/v2', 'wc/store/v1', '' for the index). */
    public function get(string $route, array $query = [], string $namespace = 'wc/v3'): ApiResponse
    {
        $path = trim($namespace.'/'.ltrim($route, '/'), '/');
        $attempt = 0;
        $retries = max(0, (int) self::config('retries', 4));
        while (true) {
            $attempt++;
            try {
                $response = $this->send($path, $query, $namespace);
            } catch (WooApiException $e) {
                if ($e->reason === 'network' && $attempt <= $retries) {
                    $this->backoff($attempt, null, $path, 'network error');

                    continue;
                }
                throw $e;
            }
            $status = $response->status();
            if (($status === 429 || in_array($status, [500, 502, 503, 504], true)) && $attempt <= $retries) {
                $this->backoff($attempt, $response->header('Retry-After'), $path, 'HTTP '.$status);

                continue;
            }

            return $this->decode($response, $path);
        }
    }

    /**
     * Every page of a collection, from $startPage: yields [page, items, totalPages|null, total|null].
     *
     * @return \Generator<int, array{0:int, 1:list<array>, 2:?int, 3:?int}>
     */
    public function pages(string $route, array $query = [], string $namespace = 'wc/v3', int $startPage = 1): \Generator
    {
        $perPage = (int) ($query['per_page'] ?? self::config('per_page', 100));
        $page = max(1, $startPage);
        while (true) {
            $response = $this->get($route, ['per_page' => $perPage, 'page' => $page] + $query, $namespace);
            $items = $response->items();
            yield [$page, $items, $response->totalPages, $response->total];
            // no X-WP-TotalPages header = an endpoint that does not paginate (it returned everything)
            $last = $response->totalPages === null || $page >= $response->totalPages;
            if ($last || ! $items) {
                return;
            }
            $page++;
        }
    }

    /** X-WP-Total of a collection (one item fetched), or the list length when the endpoint does not paginate. */
    public function count(string $route, array $query = [], string $namespace = 'wc/v3'): int
    {
        $response = $this->get($route, ['per_page' => 1] + $query, $namespace);

        return $response->total ?? count($response->items());
    }

    protected function send(string $path, array $query, string $namespace): Response
    {
        $c = $this->connection;
        $base = $c->url;
        $url = $this->routeStyle === 'rest_route' ? $base.'/' : $base.'/wp-json/'.$path;
        if ($this->routeStyle === 'rest_route') {
            $query = ['rest_route' => '/'.$path] + $query;
        }
        $target = UrlGuard::check($url, (bool) self::config('allow_private_hosts', false));
        $request = $this->request($target);

        $isWc = str_starts_with($namespace, 'wc/') && ! str_starts_with($namespace, 'wc/store');
        if ($isWc) {
            switch ($c->wcAuth()) {
                case 'basic':
                    $this->requireHttps('Basic authentication');
                    $request = $request->withBasicAuth($c->key, $c->secret);
                    break;
                case 'query':
                    $this->requireHttps('Query-string authentication');
                    $query += ['consumer_key' => $c->key, 'consumer_secret' => $c->secret];
                    break;
                case 'oauth':
                    $query = OAuth1::sign('GET', $url, $query, $c->key, $c->secret);
                    break;
            }
        } elseif ($namespace === 'wp/v2' && $c->hasWpPassword()) {
            $this->requireHttps('A WordPress application password');
            $request = $request->withBasicAuth($c->wpUser, $c->wpPassword);
        }

        $this->pause();
        $this->requests++;
        $this->log('GET '.UrlGuard::redact($url).($this->routeStyle === 'rest_route' ? '?rest_route=/'.$path : '').' '.$this->safeQuery($query));
        try {
            return $request->get($url, $query);
        } catch (ConnectionException $e) {
            throw new WooApiException('Could not connect to '.$c->host().': '.$c->mask($this->shortError($e)), 'network', null, $path);
        } catch (Throwable $e) {
            throw new WooApiException('Request to '.$c->host().' failed: '.$c->mask($this->shortError($e)), 'network', null, $path);
        }
    }

    /** A request with timeouts, TLS setting, no redirects and the connection pinned to the checked address. */
    protected function request(array $target): PendingRequest
    {
        $options = ['allow_redirects' => false, 'verify' => $this->connection->verifyTls];
        if ($target['ip'] && defined('CURLOPT_RESOLVE')) {
            $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
            $options['curl'] = [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$ip]];
        }

        return Http::timeout(max(1, (int) self::config('timeout', 30)))
            ->connectTimeout(max(1, (int) self::config('connect_timeout', 10)))
            ->withOptions($options)
            ->withHeaders(['Accept' => 'application/json', 'User-Agent' => (string) self::config('user_agent', 'PineCommerce-WooImport/1.0')]);
    }

    protected function decode(Response $response, string $path): ApiResponse
    {
        $status = $response->status();
        $c = $this->connection;
        if ($response->redirect()) {
            $location = (string) $response->header('Location');
            throw new WooApiException('The shop redirects its API to '.UrlGuard::redact($location).' – use that address as the site URL'
                .(str_starts_with($location, 'https://') && ! $c->isHttps() ? ' (https)' : '').'.', 'redirect', $status, $path);
        }
        $json = json_decode($response->body(), true);
        if ($status >= 400) {
            $code = is_array($json) ? (string) ($json['code'] ?? '') : '';
            $message = is_array($json) ? trim(strip_tags((string) ($json['message'] ?? ''))) : '';
            [$reason, $hint] = match (true) {
                $status === 401 => ['auth', $c->store ? 'This endpoint needs an API key.' : ($c->wcAuth() === 'basic'
                    ? 'Check the consumer key and secret. If they are right, the host may strip the Authorization header – choose "query string" authentication.'
                    : 'Check the consumer key and secret.')],
                $status === 403 => ['forbidden', 'The API key or WordPress user lacks permission for this data – give the key "Read" access.'],
                $status === 404 => ['not_found', $code === 'rest_no_route' ? 'This endpoint does not exist on the shop (an old WooCommerce, or the plugin is switched off).' : 'Not found.'],
                $status === 429 => ['rate_limited', 'The shop kept rate-limiting the import – try again later or raise commerce.woo_api.delay_ms.'],
                default => ['server', 'The shop answered with an error.'],
            };
            throw new WooApiException($c->mask(trim(($message !== '' ? $message.' ' : '').'(HTTP '.$status.($code !== '' ? ', '.$code : '').') '.$hint)),
                $reason, $status, $path, $code ?: null);
        }
        if ($json === null && trim($response->body()) !== 'null') {
            throw new WooApiException('The shop did not answer with JSON on '.$path.' (HTTP '.$status.') – is this a WordPress site with the REST API enabled?', 'invalid', $status, $path);
        }
        $total = $response->header('X-WP-Total');
        $pages = $response->header('X-WP-TotalPages');

        return new ApiResponse($status, $json, is_numeric($total) ? (int) $total : null, is_numeric($pages) ? (int) $pages : null, $path);
    }

    protected function backoff(int $attempt, mixed $retryAfter, string $path, string $why): void
    {
        $max = max(1, (int) self::config('max_backoff', 60));
        $seconds = is_numeric($retryAfter) ? (float) $retryAfter : (float) min($max, 2 ** ($attempt - 1));
        $seconds = min($max, max(0.0, $seconds));
        $this->log(sprintf('%s on %s – retry %d in %.1fs', $why, $path, $attempt, $seconds));
        ($this->sleeper)($seconds);
    }

    protected function pause(): void
    {
        $delay = max(0, (int) self::config('delay_ms', 250)) / 1000;
        $since = microtime(true) - $this->lastRequestAt;
        if ($this->lastRequestAt > 0 && $since < $delay) {
            ($this->sleeper)($delay - $since);
        }
        $this->lastRequestAt = microtime(true);
    }

    protected function requireHttps(string $what): void
    {
        if (! $this->connection->isHttps()) {
            throw new WooApiException($what.' is only sent over https – use an https:// site URL (or OAuth for plain http).', 'blocked');
        }
    }

    protected function safeQuery(array $query): string
    {
        $query = array_diff_key($query, array_flip(['consumer_key', 'consumer_secret', 'oauth_signature', 'oauth_consumer_key', 'oauth_nonce', 'rest_route']));

        return $query ? '?'.http_build_query($query) : '';
    }

    protected function shortError(Throwable $e): string
    {
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';

        return mb_substr(preg_replace('#https?://\S+#', '(url)', $message) ?? $message, 0, 300);
    }

    protected function log(string $line): void
    {
        if ($this->logger) {
            ($this->logger)($this->connection->mask($line));
        }
    }
}
