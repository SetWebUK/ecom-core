<?php

namespace Pine\Commerce\Tests;

/*
 * Base class of every package test. It runs in two places:
 *
 *  - inside a client app (its phpunit.xml "Commerce" suite): the app's own Tests\TestCase, so the tests boot the real
 *    bootstrap/app.php and the client's config (and its safety guards);
 *  - standalone, in the package repository (`composer test`): StandaloneTestCase (Orchestra Testbench, in-memory
 *    SQLite, package config only).
 *
 * Tests extend Pine\Commerce\Tests\TestCase and never reference the host app's classes (use Commerce::userModel()).
 */
if (! class_exists(BaseTestCase::class, false)) {
    class_alias(class_exists(\Tests\TestCase::class) ? \Tests\TestCase::class : StandaloneTestCase::class, BaseTestCase::class);
}

abstract class TestCase extends BaseTestCase
{
    /** True in the standalone package checkout (Testbench), false inside a client application. */
    protected static function standalone(): bool
    {
        return ! class_exists(\Tests\TestCase::class);
    }
}
