<?php

namespace Pine\Commerce\Extensions;

use Closure;
use Illuminate\Support\Facades\Route;

/**
 * Registers client back-office routes (Commerce::adminRoutes()) exactly like the package's own: middleware web +
 * admin (staff; add ->middleware('admin:admin') for administrators only), URL prefix commerce.admin.path, route names
 * prefixed "admin.". Skipped when the routes are cached (the cache already contains them).
 */
class AdminRoutes
{
    public static function register(Closure $routes): void
    {
        if (app()->routesAreCached()) {
            return;
        }
        Route::middleware(['web', 'admin'])
            ->prefix(trim((string) config('commerce.admin.path', 'admin'), '/'))
            ->name('admin.')
            ->group($routes);

        $collection = app('router')->getRoutes();
        $collection->refreshNameLookups();
        $collection->refreshActionLookups();
    }
}
