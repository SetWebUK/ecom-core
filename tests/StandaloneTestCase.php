<?php

namespace Pine\Commerce\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Testbench;
use Pine\Commerce\Commerce;
use Pine\Commerce\CommerceServiceProvider;
use RuntimeException;

/**
 * Standalone test harness of the package repository (`composer test`): Orchestra Testbench wired like a client app –
 * the same bootstrap as stubs/client-skeleton/bootstrap/app.php (Commerce::middleware / Commerce::exceptions), the
 * skeleton's base migrations (users, cache, jobs) and the package's default config, on phpunit's in-memory SQLite.
 *
 * Never used inside a client app (see TestCase). Only loaded when orchestra/testbench is installed (require-dev).
 */
abstract class StandaloneTestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [CommerceServiceProvider::class];
    }

    /** The client skeleton's bootstrap/app.php, on Testbench's Laravel skeleton directory. */
    protected function resolveApplication()
    {
        return (new ApplicationBuilder(new Application($this->getApplicationBasePath())))
            ->withProviders()
            ->withMiddleware(fn (Middleware $middleware) => Commerce::middleware($middleware))
            ->withExceptions(fn (Exceptions $exceptions) => Commerce::exceptions($exceptions))
            ->withCommands()
            ->create();
    }

    protected function defineEnvironment($app): void
    {
        $skeleton = dirname(__DIR__).'/stubs/client-skeleton';
        $config = $app['config'];

        // phpunit.xml.dist's in-memory SQLite as the "sqlite" default connection (what the package's safety checks expect)
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite.database', ':memory:');
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('p', 32)));
        $config->set('auth.providers.users.model', \Pine\Commerce\Models\User::class);

        // the skeleton's read-only "wordpress" and prefixed "scratch" connections (the tests that need MySQL skip)
        $connections = (require $skeleton.'/config/database.php')['connections'] ?? [];
        foreach (['wordpress', 'scratch'] as $name) {
            if (isset($connections[$name]) && ! $config->has("database.connections.{$name}")) {
                $config->set("database.connections.{$name}", $connections[$name]);
            }
        }
    }

    /** Users / cache / jobs tables exactly as a new client project has them (the package migrations extend users). */
    protected function defineDatabaseMigrations(): void
    {
        $skeleton = dirname(__DIR__).'/stubs/client-skeleton/database/migrations';
        $this->app->afterResolving('migrator', fn ($migrator) => $migrator->path($skeleton));
        if ($this->app->resolved('migrator')) {
            $this->app['migrator']->path($skeleton);
        }
    }

    /** Same guard as a client app's tests/TestCase.php: destructive testing traits only on in-memory SQLite. */
    protected function setUpTraits()
    {
        $uses = class_uses_recursive(static::class);
        if (isset($uses[RefreshDatabase::class]) || isset($uses[DatabaseMigrations::class]) || isset($uses[DatabaseTruncation::class])) {
            $name = config('database.default');
            if (config("database.connections.{$name}.driver") !== 'sqlite' || config("database.connections.{$name}.database") !== ':memory:') {
                throw new RuntimeException("Refusing to refresh the database: default connection [{$name}] is not in-memory SQLite.");
            }
        }

        return parent::setUpTraits();
    }
}
