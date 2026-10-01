<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Pine\Commerce\Tests\TestCase;

/**
 * Risk R15 (ARCHITECTURE.md §17): the storefront catch-all is a *fallback* route, so routes registered after it
 * (theme / client routes/web.php) still match, and it never swallows the back office.
 */
class RouteFallbackOrderTest extends TestCase
{
    public function test_catch_all_is_a_fallback_route(): void
    {
        $route = Route::getRoutes()->getByName('resolve');

        $this->assertNotNull($route);
        $this->assertTrue($route->isFallback);
    }

    public function test_a_route_registered_after_the_catch_all_still_matches(): void
    {
        Route::middleware('web')->get('commerce-fallback-probe/{x}', fn (string $x) => 'probe:'.$x);
        Route::getRoutes()->refreshNameLookups();

        $matched = Route::getRoutes()->match(request()->create('/commerce-fallback-probe/abc/'));

        $this->assertSame('commerce-fallback-probe/{x}', $matched->uri());
    }

    public function test_catch_all_never_matches_the_back_office_or_published_assets(): void
    {
        foreach (['admin/anything', 'vendor/commerce/admin/js/admin.js', 'themes/default/x.css', 'storage/x.jpg'] as $path) {
            try {
                $name = Route::getRoutes()->match(request()->create('/'.$path))->getName();
            } catch (NotFoundHttpException) {
                $name = null; // no route at all: the web server serves the file (or a plain 404)
            }
            $this->assertNotSame('resolve', $name, $path);
        }
    }
}
