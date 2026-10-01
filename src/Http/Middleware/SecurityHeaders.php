<?php

namespace Pine\Commerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response (storefront, back office, errors):
 *   X-Frame-Options: SAMEORIGIN            no framing by other sites (clickjacking)
 *   X-Content-Type-Options: nosniff        no MIME sniffing of responses
 *   Referrer-Policy: strict-origin-when-cross-origin   no full URLs (order keys, reset tokens) sent to other sites
 * Headers a controller already set are left alone. Plain-HTTP page requests are sent to HTTPS when the
 * site URL is https, so session / basket cookies are never issued over an unencrypted connection.
 */
class SecurityHeaders
{
    public const HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isSecure() && in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && str_starts_with((string) config('app.url'), 'https://') && ! app()->runningInConsole()) {
            // canonical host from APP_URL (never the client's Host header)
            return $this->withHeaders(redirect()->away(rtrim((string) config('app.url'), '/').$request->getRequestUri(), 301));
        }

        return $this->withHeaders($next($request));
    }

    protected function withHeaders(Response $response): Response
    {
        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
