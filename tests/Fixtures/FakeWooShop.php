<?php

namespace Pine\Commerce\Tests\Fixtures;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pine\Commerce\Import\WooApi\OAuth1;
use Pine\Commerce\Import\WooApi\UrlGuard;

/**
 * A WooCommerce shop behind Http::fake() for the REST API importer tests: serves the JSON in tests/Fixtures/woo-api
 * (wc/v3, wp/v2 and the Store API, shaped like WooCommerce 9.9's REST controllers), paginates lists like WordPress
 * (per_page/page + X-WP-Total/X-WP-TotalPages; non-paginated endpoints return everything), filters modified_after /
 * after, checks the API key the way WooCommerce does (Basic, query string or an OAuth 1.0a signature), serves small
 * generated images for wp-content/uploads URLs and can fail requests on purpose (429 / 5xx).
 */
class FakeWooShop
{
    public const BASE = 'https://old-shop.example.test';

    public const KEY = 'ck_0123456789abcdef0123456789abcdef01234567';

    public const SECRET = 'cs_fedcba9876543210fedcba9876543210fedcba98';

    /** WooCommerce/WordPress endpoints that return the whole list (no pagination headers). */
    public const NOT_PAGINATED = ['wc/v3/products/attributes', 'wc/v3/taxes/classes', 'wc/v3/shipping/zones', 'wc/store/v1/products/attributes'];

    /** @var list<array{url:string, path:string, query:array, headers:array}> */
    public array $requests = [];

    /** route => queue of [status, headers] answered before the real response */
    public array $failures = [];

    /** route => data replacing the fixture */
    public array $override = [];

    /** expected wc/v3 authentication: basic | query | oauth */
    public string $auth = 'basic';

    public static function fake(string $auth = 'basic', string $ip = '93.184.216.34'): self
    {
        $shop = new self;
        $shop->auth = $auth;
        UrlGuard::$resolver = fn (string $host) => [$ip];
        Http::fake(fn (Request $request) => $shop->respond($request));

        return $shop;
    }

    public function respond(Request $request)
    {
        $url = $request->url();
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $path = (string) ($parts['path'] ?? '/');
        $this->requests[] = ['url' => $url, 'path' => $path, 'query' => $query, 'headers' => $request->headers()];

        if (str_contains($path, '/wp-content/uploads/')) {
            return $this->image($path);
        }
        $route = isset($query['rest_route']) ? trim((string) $query['rest_route'], '/') : trim(Str::after($path, '/wp-json'), '/');
        if (! empty($this->failures[$route])) {
            [$status, $headers] = array_shift($this->failures[$route]);

            return Http::response(['code' => 'rest_error', 'message' => 'Busy', 'data' => ['status' => $status]], $status, $headers);
        }
        if (str_starts_with($route, 'wc/v3') && ! $this->authorised($request, $parts, $query)) {
            return Http::response(['code' => 'woocommerce_rest_cannot_view', 'message' => 'Sorry, you cannot list resources.', 'data' => ['status' => 401]], 401);
        }
        $data = array_key_exists($route, $this->override) ? $this->override[$route] : $this->fixture($route);
        if ($data === null) {
            return Http::response(['code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.', 'data' => ['status' => 404]], 404);
        }
        if (is_array($data) && array_is_list($data)) {
            if (isset($query['modified_after'])) {
                $after = strtotime($query['modified_after'].' UTC');
                $data = array_values(array_filter($data, fn ($i) => strtotime(($i['date_modified_gmt'] ?? $i['modified_gmt'] ?? '1970-01-01').' UTC') > $after));
            }
            if (isset($query['after'])) {
                $after = strtotime($query['after'].' UTC');
                $data = array_values(array_filter($data, fn ($i) => strtotime(($i['date_created_gmt'] ?? '1970-01-01').' UTC') > $after));
            }
            if (in_array($route, self::NOT_PAGINATED, true) || preg_match('#^wc/v3/(shipping/zones/\d+/(locations|methods)|orders/\d+/notes)$#', $route)) {
                return Http::response($data, 200);
            }
            $perPage = max(1, (int) ($query['per_page'] ?? 10));
            $page = max(1, (int) ($query['page'] ?? 1));
            $total = count($data);

            return Http::response(array_slice($data, ($page - 1) * $perPage, $perPage), 200,
                ['X-WP-Total' => (string) $total, 'X-WP-TotalPages' => (string) max(1, (int) ceil($total / $perPage))]);
        }

        return Http::response($data, 200);
    }

    /** Requests made to a route (wc/v3/products …). */
    public function calls(string $route): array
    {
        return array_values(array_filter($this->requests, fn ($r) => trim(isset($r['query']['rest_route']) ? $r['query']['rest_route'] : Str::after($r['path'], '/wp-json'), '/') === $route));
    }

    protected function fixture(string $route): mixed
    {
        $dir = dirname(__DIR__).'/Fixtures/woo-api';
        if ($route === '') {
            $file = $dir.'/index.json';
        } else {
            foreach (['wc/v3/' => 'wc-v3', 'wp/v2/' => 'wp-v2', 'wc/store/v1/' => 'store'] as $prefix => $folder) {
                if (str_starts_with($route, $prefix)) {
                    $file = $dir.'/'.$folder.'/'.str_replace('/', '-', substr($route, strlen($prefix))).'.json';
                }
            }
        }

        return isset($file) && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    protected function authorised(Request $request, array $parts, array $query): bool
    {
        switch ($this->auth) {
            case 'basic':
                return $request->header('Authorization') === ['Basic '.base64_encode(self::KEY.':'.self::SECRET)];
            case 'query':
                return ($query['consumer_key'] ?? null) === self::KEY && ($query['consumer_secret'] ?? null) === self::SECRET && ! $request->hasHeader('Authorization');
            case 'oauth':
                if (($query['oauth_consumer_key'] ?? null) !== self::KEY || empty($query['oauth_signature']) || empty($query['oauth_nonce'])) {
                    return false;
                }
                $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$parts['path'];

                return hash_equals(OAuth1::signature('GET', $base, $query, self::SECRET), (string) $query['oauth_signature']);
        }

        return false;
    }

    /** An 8×8 PNG/JPEG whose colour depends on the path (so different files differ); "evil.jpg" is not an image. */
    protected function image(string $path)
    {
        if (str_ends_with($path, 'evil.jpg')) {
            return Http::response('<?php echo "pwned"; ?>', 200, ['Content-Type' => 'image/jpeg']);
        }
        $hash = crc32($path);
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, $hash & 255, ($hash >> 8) & 255, ($hash >> 16) & 255));
        ob_start();
        str_ends_with($path, '.png') ? imagepng($image) : imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        return Http::response($bytes, 200, ['Content-Type' => str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg', 'Content-Length' => (string) strlen($bytes)]);
    }
}
