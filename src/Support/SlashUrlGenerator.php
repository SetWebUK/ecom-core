<?php

namespace Pine\Commerce\Support;

use Pine\Commerce\Http\Middleware\TrailingSlash;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Str;

/**
 * Generates WordPress-style page URLs with a trailing slash (/shop/, /my-account/orders/),
 * so links match the legacy site exactly and never bounce through a redirect.
 */
class SlashUrlGenerator extends UrlGenerator
{
    public function format($root, $path, $route = null)
    {
        $url = parent::format($root, $path, $route);
        $urlPath = parse_url($url, PHP_URL_PATH) ?? '';

        return $urlPath !== '' && TrailingSlash::shouldHaveSlash($urlPath) && ! str_ends_with($url, '/') ? $url.'/' : $url;
    }

    /**
     * The previous URL with this site's trailing slash. Laravel stores it in the session from Request::fullUrl(),
     * which strips the slash (/shop?x=1 for /shop/?x=1), so every back() / redirect()->back() cost an extra 301
     * through the TrailingSlash middleware. Referers and session URLs of other hosts are returned unchanged.
     */
    public function previous($fallback = false)
    {
        return $this->withTrailingSlash(parent::previous($fallback));
    }

    /** Add the trailing slash to a URL of this site whose path should carry one (query string and fragment kept). */
    public function withTrailingSlash(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }
        $root = parse_url($this->to('/'));
        if (strcasecmp((string) $parts['host'], (string) ($root['host'] ?? '')) !== 0) {
            return $url;
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || str_ends_with($path, '/') || ! TrailingSlash::shouldHaveSlash($path)) {
            return $url;
        }
        $cut = strcspn($url, '?#', strlen($parts['scheme'] ?? '') + 3); // first ? or # after "scheme://"
        $cut += strlen($parts['scheme'] ?? '') + 3;

        return substr($url, 0, $cut).'/'.substr($url, $cut);
    }

    /**
     * Signed URLs (URL::signedRoute()) are signed as generated – with the trailing slash format() adds – but Laravel
     * checks the signature against the request URL without it. Accept the slashed form too.
     */
    public function hasCorrectSignature(Request $request, $absolute = true, Closure|array $ignoreQuery = [])
    {
        if (parent::hasCorrectSignature($request, $absolute, $ignoreQuery)) {
            return true;
        }
        $signature = $request->query('signature');
        if (! $absolute || ! is_string($signature) || ! TrailingSlash::shouldHaveSlash($request->getPathInfo())) {
            return false;
        }
        $url = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo(), '/').'/';
        $query = collect(explode('&', (string) $request->server->get('QUERY_STRING')))
            ->reject(function ($parameter) use ($ignoreQuery) {
                $parameter = Str::before($parameter, '=');

                return $parameter === 'signature' || ($ignoreQuery instanceof Closure ? $ignoreQuery($parameter) : in_array($parameter, $ignoreQuery));
            })->join('&');
        $original = rtrim($url.'?'.$query, '?');

        foreach ((array) call_user_func($this->keyResolver) as $key) {
            if (hash_equals(hash_hmac('sha256', $original, $key), $signature)) {
                return true;
            }
        }

        return false;
    }
}
