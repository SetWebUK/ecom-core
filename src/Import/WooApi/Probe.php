<?php

namespace Pine\Commerce\Import\WooApi;

use Throwable;

/**
 * "Test connection": what the shop is, which API the importer can use and how much there is to import.
 *
 * Returns store name/URL (wp-json index), WordPress + WooCommerce versions and currency (system_status), the number of
 * items per entity (X-WP-Total) and every permission problem per entity (401/403/404 with WooCommerce's message).
 */
class Probe
{
    /** entity => [route, namespace, query] for the REST (key) mode */
    public const REST_COUNTS = [
        'products' => ['products', 'wc/v3', []],
        'categories' => ['products/categories', 'wc/v3', []],
        'attributes' => ['products/attributes', 'wc/v3', []],
        'customers' => ['customers', 'wc/v3', ['role' => 'all']],
        'orders' => ['orders', 'wc/v3', []],
        'coupons' => ['coupons', 'wc/v3', []],
        'reviews' => ['products/reviews', 'wc/v3', []],
        'tax_rates' => ['taxes', 'wc/v3', []],
        'shipping_zones' => ['shipping/zones', 'wc/v3', []],
        'pages' => ['pages', 'wp/v2', []],
        'posts' => ['posts', 'wp/v2', []],
        'media' => ['media', 'wp/v2', ['media_type' => 'image']],
    ];

    public const STORE_COUNTS = [
        'products' => ['products', 'wc/store/v1', []],
        'categories' => ['products/categories', 'wc/store/v1', []],
        'attributes' => ['products/attributes', 'wc/store/v1', []],
        'pages' => ['pages', 'wp/v2', []],
        'posts' => ['posts', 'wp/v2', []],
        'media' => ['media', 'wp/v2', ['media_type' => 'image']],
    ];

    public function __construct(private readonly Client $client) {}

    /**
     * @return array{ok:bool, error:?string, store:array, versions:array, counts:array<string,?int>, problems:array<string,string>,
     *               auth:string, route_style:string, namespaces:list<string>}
     */
    public function run(): array
    {
        $c = $this->client->connection;
        $out = ['ok' => false, 'error' => null, 'store' => [], 'versions' => [], 'counts' => [], 'problems' => [],
            'auth' => $c->wcAuth(), 'route_style' => 'wp-json', 'namespaces' => []];
        try {
            $index = $this->index();
        } catch (Throwable $e) {
            $out['error'] = $c->mask($e->getMessage());

            return $out;
        }
        $out['route_style'] = $this->client->routeStyle;
        $out['namespaces'] = array_values(array_map('strval', (array) ($index['namespaces'] ?? [])));
        $out['store'] = ['name' => html_entity_decode((string) ($index['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => html_entity_decode((string) ($index['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'url' => (string) ($index['url'] ?? ''), 'home' => (string) ($index['home'] ?? ''),
            'page_on_front' => (int) ($index['page_on_front'] ?? 0), 'page_for_posts' => (int) ($index['page_for_posts'] ?? 0)];
        $hasWc = in_array('wc/v3', $out['namespaces'], true);
        $hasStore = in_array('wc/store/v1', $out['namespaces'], true) || in_array('wc/store', $out['namespaces'], true);
        if ($c->store ? ! $hasStore : ! $hasWc) {
            $out['error'] = $c->store
                ? 'This site has no WooCommerce Store API (wc/store/v1) – is WooCommerce active?'
                : 'This site has no WooCommerce REST API (wc/v3) – is WooCommerce active?';

            return $out;
        }

        if (! $c->store) {
            try {
                $status = $this->client->get('system_status', ['_fields' => 'environment,settings'])->json;
                $out['versions'] = ['woocommerce' => (string) ($status['environment']['version'] ?? ''),
                    'wordpress' => (string) ($status['environment']['wp_version'] ?? ''), 'currency' => (string) ($status['settings']['currency'] ?? '')];
            } catch (WooApiException $e) {
                if (in_array($e->reason, ['auth'], true)) {
                    $out['error'] = 'The shop refused the API key: '.$e->getMessage();

                    return $out;
                }
                $out['problems']['system_status'] = $e->getMessage();
            }
        }

        foreach ($c->store ? self::STORE_COUNTS : self::REST_COUNTS as $entity => [$route, $namespace, $query]) {
            try {
                $out['counts'][$entity] = $this->client->count($route, $query, $namespace);
            } catch (WooApiException $e) {
                $out['counts'][$entity] = null;
                $out['problems'][$entity] = $e->getMessage();
                if ($e->reason === 'auth' && ! $c->store && $namespace === 'wc/v3' && ! isset($out['error'])) {
                    $out['error'] = 'The shop refused the API key: '.$e->getMessage();
                }
            }
        }
        $out['ok'] = $out['error'] === null;

        return $out;
    }

    /**
     * The WordPress REST index (no authentication), trying /wp-json/ and then ?rest_route=/ (sites without pretty
     * permalinks); the working style is kept on the client.
     */
    public function index(): array
    {
        try {
            $index = $this->client->get('', [], '')->json;
            if (is_array($index) && isset($index['namespaces'])) {
                return $index;
            }
        } catch (WooApiException $e) {
            if (! in_array($e->reason, ['not_found', 'invalid'], true)) {
                throw $e;
            }
        }
        $this->client->routeStyle = 'rest_route';
        $index = $this->client->get('', [], '')->json;
        if (! is_array($index) || ! isset($index['namespaces'])) {
            throw new WooApiException('No WordPress REST API found at '.$this->client->connection->url.' – check the address.', 'invalid');
        }

        return $index;
    }
}
