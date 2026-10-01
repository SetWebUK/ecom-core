<?php

namespace Pine\Commerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pine\Commerce\Theme\ThemeManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff theme preview: /any-page/?preview_theme={slug} renders the storefront with another installed theme for the
 * signed-in staff member only (remembered in their session while they browse), ?preview_theme=off ends it.
 *
 * Customers and guests are never affected: the query parameter is ignored unless the user can access the back office,
 * and the session flag is re-checked against the user on every request. Preview responses are private, no-store and
 * noindex, and carry a small "Previewing theme" bar injected before </body>.
 */
class PreviewTheme
{
    public const PARAM = 'preview_theme';

    public const SESSION = 'commerce.preview_theme';

    public function __construct(protected ThemeManager $themes) {}

    public function handle(Request $request, Closure $next): Response
    {
        $adminPath = trim((string) config('commerce.admin.path', 'admin'), '/');
        if (! $request->hasSession() || $request->is($adminPath, $adminPath.'/*')) {
            return $next($request); // the back office never previews storefront themes
        }
        $session = $request->session();
        $asked = $request->query(self::PARAM);
        $stored = $session->get(self::SESSION);

        if ($asked === null && $stored === null) {
            return $next($request); // the normal case: no work, no user lookup
        }

        $user = $request->user();
        if (! $user || ! method_exists($user, 'canAccessAdmin') || ! $user->canAccessAdmin()) {
            if ($stored !== null) {
                $session->forget(self::SESSION);
            }

            return $next($request);
        }

        if (is_string($asked)) {
            $asked = trim($asked);
            if ($asked === '' || in_array($asked, ['off', '0', 'exit'], true) || $asked === $this->themes->configuredSlug()) {
                $session->forget(self::SESSION);
                $stored = null;
            } elseif ($this->themes->usable($asked)) {
                $session->put(self::SESSION, $asked);
                $stored = $asked;
            }
        }

        if (! is_string($stored) || ! $this->themes->usable($stored)) {
            return $next($request);
        }

        $this->themes->preview($stored);
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $this->injectBar($response, $stored);
    }

    protected function injectBar(Response $response, string $slug): Response
    {
        $type = (string) $response->headers->get('Content-Type');
        if (($type !== '' && ! str_contains($type, 'text/html')) || ! method_exists($response, 'getContent')) {
            return $response;
        }
        $html = (string) $response->getContent();
        $pos = strripos($html, '</body>');
        if ($pos === false) {
            return $response;
        }
        $theme = $this->themes->get($slug);
        $exit = e(request()->fullUrlWithQuery([self::PARAM => 'off']));
        $bar = '<div id="commerce-theme-preview" role="status" style="position:fixed;left:12px;bottom:12px;z-index:2147483000;'
            .'display:flex;gap:10px;align-items:center;padding:8px 12px;border-radius:10px;background:#111827;color:#fff;'
            .'font:500 13px/1.3 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.25)">'
            .'<span>Previewing theme <strong>'.e($theme->name).'</strong> (only you can see this)</span>'
            .'<a href="'.$exit.'" style="color:#93c5fd;text-decoration:underline">Exit preview</a></div>';
        $response->setContent(substr($html, 0, $pos).$bar.substr($html, $pos));

        return $response;
    }
}
