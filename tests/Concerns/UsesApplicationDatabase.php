<?php

namespace Pine\Commerce\Tests\Concerns;

/**
 * Boot the application with the .env database settings instead of phpunit.xml's in-memory SQLite, so a test can use
 * the prefixed "scratch" connection (same MySQL database, zz_… tables). Never write to the real tables: set the
 * scratch prefix in setUp() and drop the zz_ tables in tearDown() (ScratchDropCommand::drop()).
 */
trait UsesApplicationDatabase
{
    public function createApplication()
    {
        if (static::standalone()) {
            $this->markTestSkipped('Needs a client application\'s MySQL database (.env) and scratch connection.');
        }

        $keys = ['DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $app = parent::createApplication();

        foreach ($saved as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        // phpdotenv's immutable writer remembers the DB_* values it loaded above and would OVERWRITE the restored
        // phpunit values (sqlite :memory:) on the next test's boot – every later test then ran on the MySQL database
        // (and a RefreshDatabase test wiped it). A fresh repository forgets them.
        \Illuminate\Support\Env::enablePutenv();

        if ($app['config']->get('database.connections.scratch.driver') !== 'mysql' || $app['config']->get('database.default') === 'sqlite') {
            $this->markTestSkipped('Needs the application MySQL database (.env) and the scratch connection.');
        }

        return $app;
    }

    /** Point the scratch connection at a test-specific zz_ prefix. */
    protected function useScratchPrefix(string $prefix): void
    {
        if (! str_starts_with($prefix, 'zz_')) {
            throw new \InvalidArgumentException('Scratch prefixes must start with zz_.');
        }
        config(['database.connections.scratch.prefix' => $prefix]);
        \Illuminate\Support\Facades\DB::purge('scratch');
    }
}
