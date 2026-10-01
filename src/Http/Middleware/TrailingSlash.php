<?php

namespace Pine\Commerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Legacy WordPress URLs always end in "/". Redirect slash-less GET page URLs
 * to their canonical slashed form so every URL has exactly one address.
 */
class TrailingSlash
{
    public const EXCLUDED_PREFIXES = ['admin', 'storage', 'ajax', 'cart', 'webhooks', 'feeds', 'up'];

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') && ! $request->ajax() && ! $request->expectsJson()) {
            $uri = $request->getRequestUri();
            $path = parse_url($uri, PHP_URL_PATH) ?? '/';
            if (self::shouldHaveSlash($path) && ! str_ends_with($path, '/')) {
                $query = parse_url($uri, PHP_URL_QUERY);

                return redirect()->to($path.'/'.($query ? '?'.$query : ''), 301);
            }
        }

        return $next($request);
    }

    public static function shouldHaveSlash(string $path): bool
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '' || str_contains(basename($trimmed), '.')) {
            return false;
        }
        $first = explode('/', $trimmed)[0];

        return ! in_array($first, self::EXCLUDED_PREFIXES, true);
    }
}
