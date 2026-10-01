<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Smoke test that the client app is wired to pine/commerce (phpunit.xml ignores the production caches):
 * `composer test` or `php artisan test`.
 */
class PlatformTest extends TestCase
{
    public function test_the_store_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('shop'));
        $this->assertTrue(Route::has('admin.dashboard'));
        $this->assertTrue(Route::getRoutes()->getByName('resolve')->isFallback);
    }

    public function test_the_commerce_commands_are_available(): void
    {
        $this->artisan('list')->expectsOutputToContain('commerce:install')->assertSuccessful();
    }
}
