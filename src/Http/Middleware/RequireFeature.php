<?php

namespace Pine\Commerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pine\Commerce\Support\Features;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: 404 unless every named feature switch is on (config commerce.features.*).
 *
 *   Route::post('wishlist/toggle', …)->middleware(RequireFeature::for('wishlist'));
 *
 * Route names stay registered (theme views can still build URLs and a cached route file stays valid when a switch
 * changes); the routes simply do not answer. Storefront routes also need the active theme to support a view-bearing
 * feature (Features::THEME_SUPPORTS); back-office routes (names admin.*) check the switch only.
 */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $admin = (bool) $request->route()?->named('admin.*');
        foreach ($features as $feature) {
            if (! Features::enabled($feature, ! $admin)) {
                abort(404);
            }
        }

        return $next($request);
    }

    /** Middleware definition for a route: RequireFeature::for('blog') === 'Pine\Commerce\Http\Middleware\RequireFeature:blog'. */
    public static function for(string ...$features): string
    {
        return static::class.':'.implode(',', $features);
    }
}
