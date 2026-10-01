<?php

namespace Pine\Commerce\Services\Checkout;

use Illuminate\Http\Request;

/**
 * Order attribution (WooCommerce "Origin" / sourcebuster equivalent).
 *
 * The theme's JS records where the visitor came from in the attribution cookie (config
 * commerce.checkout.attribution_cookie (a client may keep its legacy cookie name); plain JSON, excluded
 * from cookie encryption): utm parameters, the external referrer and the landing page. At checkout the
 * values are cleaned here and stored on the order as source_type (utm | organic | referral | typein) and
 * source (utm_source, search engine or referring domain, "(direct)"), with the details in meta.attribution.
 */
class Attribution
{
    /** Name of the attribution cookie (config commerce.checkout.attribution_cookie). */
    public static function cookieName(): string
    {
        return (string) (config('commerce.checkout.attribution_cookie') ?: 'commerce_attr');
    }

    /** Search engines whose visits count as organic even without a query string (like sourcebuster's google rule). */
    protected const ENGINES = [
        'google' => '/(^|\.)google\.[a-z.]+$/',
        'bing' => '/(^|\.)bing\.com$/',
        'yahoo' => '/(^|\.)search\.yahoo\.com$/',
        'duckduckgo' => '/(^|\.)duckduckgo\.com$/',
        'ecosia' => '/(^|\.)ecosia\.org$/',
        'brave' => '/(^|\.)search\.brave\.com$/',
        'yandex' => '/(^|\.)yandex\.[a-z.]+$/',
        'baidu' => '/(^|\.)baidu\.com$/',
    ];

    /** @return array{source_type: string, source: string, meta: array<string, string>} */
    public static function fromRequest(Request $request): array
    {
        $cookie = self::cookieName();
        $raw = $request->cookie($cookie);
        if (! is_string($raw) || $raw === '') {
            $raw = $_COOKIE[$cookie] ?? '';
        }
        $data = is_string($raw) && strlen($raw) < 4000 ? json_decode(rawurldecode($raw), true) : null;
        $data = is_array($data) ? $data : [];

        $clean = fn ($key, $max = 190) => isset($data[$key]) && is_scalar($data[$key])
            ? mb_substr(trim(strip_tags((string) $data[$key])), 0, $max)
            : '';

        $utmSource = $clean('utm_source', 100);
        $referrer = $clean('referrer', 500);
        $referrer = preg_match('#^https?://#i', $referrer) ? $referrer : ''; // never a javascript:/data: URL (shown as a link in the back office)
        $landing = $clean('landing', 500);
        $landing = preg_match('#^/(?![/\\\\])#', $landing) ? $landing : '';
        $refHost = $referrer !== '' ? strtolower((string) parse_url($referrer, PHP_URL_HOST)) : '';
        $refHost = preg_replace('/^www\./', '', $refHost) ?? '';
        $ownHost = preg_replace('/^www\./', '', strtolower((string) $request->getHost())) ?? '';
        if ($refHost === $ownHost) {
            $refHost = '';
        }

        if ($utmSource !== '') {
            $type = 'utm';
            $source = $utmSource;
        } elseif ($refHost !== '') {
            $type = 'referral';
            $source = $refHost;
            foreach (self::ENGINES as $name => $pattern) {
                if (preg_match($pattern, $refHost)) {
                    $type = 'organic';
                    $source = $name;
                    break;
                }
            }
        } else {
            $type = 'typein';
            $source = '(direct)';
        }

        $meta = array_filter([
            'source_type' => $type,
            'utm_source' => $utmSource,
            'utm_medium' => $clean('utm_medium', 100),
            'utm_campaign' => $clean('utm_campaign', 150),
            'utm_content' => $clean('utm_content', 150),
            'utm_term' => $clean('utm_term', 150),
            'gclid' => $clean('gclid', 190) !== '' ? 'yes' : '',
            'referrer' => $referrer,
            'landing_page' => $landing,
            'first_visit' => $clean('ts', 40),
            'session_pages' => $clean('pages', 6),
            'device_type' => static::deviceType((string) $request->userAgent()),
        ], fn ($v) => $v !== '');

        return ['source_type' => $type, 'source' => mb_substr($source, 0, 190), 'meta' => $meta];
    }

    public static function deviceType(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('/ipad|tablet|kindle|silk|playbook/i', $userAgent) => 'Tablet',
            (bool) preg_match('/mobi|iphone|android|blackberry|opera mini|iemobile/i', $userAgent) => 'Mobile',
            default => 'Desktop',
        };
    }
}
