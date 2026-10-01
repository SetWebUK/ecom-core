<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety net: RefreshDatabase / DatabaseMigrations / DatabaseTruncation run migrate:fresh or truncate, which
     * DESTROYS every table of the default connection. They may only ever run on phpunit's in-memory SQLite – never on
     * the application's MySQL database (real client data), whatever leaked into the environment before this test.
     */
    protected function setUpTraits()
    {
        $uses = class_uses_recursive(static::class);
        if (isset($uses[RefreshDatabase::class]) || isset($uses[DatabaseMigrations::class]) || isset($uses[DatabaseTruncation::class])) {
            $name = config('database.default');
            $driver = config("database.connections.{$name}.driver");
            $database = config("database.connections.{$name}.database");
            if ($driver !== 'sqlite' || $database !== ':memory:') {
                throw new RuntimeException("Refusing to refresh the database: default connection [{$name}] is {$driver} "
                    ."[{$database}], not phpunit's in-memory SQLite (did a previous test leak DB_* environment variables?).");
            }
        }

        return parent::setUpTraits();
    }
}
