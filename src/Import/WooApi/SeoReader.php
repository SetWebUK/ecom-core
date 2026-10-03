<?php

namespace Pine\Commerce\Import\WooApi;

use Pine\Commerce\Import\Data\SeoData;
use Pine\Commerce\Import\Support\Formatter;

/**
 * SEO titles/descriptions from what the REST API exposes, first answer wins:
 *
 *  1. Yoast SEO: `yoast_head_json` (title, description, robots) on products, terms, pages and posts;
 *  2. Rank Math: its post meta in `meta_data` (rank_math_title / rank_math_description / rank_math_focus_keyword /
 *     rank_math_robots – %variables% resolved like the database importer);
 *  3. Rank Math headless support (rankmath/v1/getHead?url=…, when the shop switched it on): the rendered head;
 *  4. fallback: no title (the storefront's default "Name | Store") and the first 30 words of the short
 *     description / excerpt / description as the meta description.
 */
class SeoReader
{
    private ?bool $rankMathHead = null;

    public function __construct(private readonly ?Client $client = null, private readonly bool $useHead = true) {}

    /** @param array $item a product / page / post / term array; $vars SEO variables (title, excerpt, term …) */
    public function read(array $item, array $vars, ?string $url, ?string $fallbackText): SeoData
    {
        if (is_array($yoast = $item['yoast_head_json'] ?? null) && (($yoast['title'] ?? '') !== '' || ($yoast['description'] ?? '') !== '')) {
            $robots = (array) ($yoast['robots'] ?? []);

            return new SeoData(
                self::clean($yoast['title'] ?? null),
                self::clean($yoast['description'] ?? $yoast['og_description'] ?? null),
                null,
                ($robots['index'] ?? 'index') === 'noindex',
                is_string($yoast['canonical'] ?? null) ? $yoast['canonical'] : null,
            );
        }
        $meta = ApiMap::meta((array) ($item['meta_data'] ?? []));
        if (($meta['rank_math_title'] ?? '') !== '' || ($meta['rank_math_description'] ?? '') !== '' || ($meta['rank_math_focus_keyword'] ?? '') !== '') {
            $robots = is_array($meta['rank_math_robots'] ?? null) ? $meta['rank_math_robots'] : [];

            return new SeoData(Formatter::seo($meta['rank_math_title'] ?? null, $vars), Formatter::seo($meta['rank_math_description'] ?? null, $vars),
                trim(explode(',', (string) ($meta['rank_math_focus_keyword'] ?? ''))[0]) ?: null, in_array('noindex', $robots, true));
        }
        if ($url && ($head = $this->rankMathHead($url))) {
            return $head;
        }

        return new SeoData(null, $fallbackText !== null ? (Formatter::excerpt($fallbackText, 30) ?: null) : null);
    }

    /** Rank Math's rendered head for a URL, when its headless support is on (checked once per run). */
    protected function rankMathHead(string $url): ?SeoData
    {
        if (! $this->client || ! $this->useHead || $this->rankMathHead === false) {
            return null;
        }
        try {
            $response = $this->client->get('getHead', ['url' => $url], 'rankmath/v1');
        } catch (WooApiException) {
            $this->rankMathHead = false; // route missing (headless support off) – don't ask again

            return null;
        }
        $this->rankMathHead = true;
        $html = is_array($response->json) ? (string) ($response->json['head'] ?? '') : '';
        if ($html === '') {
            return null;
        }
        $title = preg_match('#<title>(.*?)</title>#s', $html, $m) ? self::clean($m[1]) : null;
        $description = preg_match('#<meta name="description" content="([^"]*)"#', $html, $m) ? self::clean($m[1]) : null;
        $noindex = (bool) preg_match('#<meta name="robots" content="[^"]*noindex#', $html);

        return $title || $description ? new SeoData($title, $description, null, $noindex) : null;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = Formatter::replace(Formatter::decode($value));

        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
