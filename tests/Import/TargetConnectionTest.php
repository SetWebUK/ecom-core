<?php

namespace Pine\Commerce\Tests\Import;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Tests\TestCase;

/**
 * --target only switches the default connection. It must never purge it: services resolved earlier (the database
 * cache store, …) keep the old Connection object, and when that one reconnects the manager refreshes the PDO of the
 * live connection as well – resetting its transaction level, so a `--target=… --dry-run` committed its writes.
 */
class TargetConnectionTest extends TestCase
{
    public function test_target_switch_keeps_the_resolved_connection(): void
    {
        $default = config('database.default');
        if (config("database.connections.{$default}.driver") !== 'sqlite' || config("database.connections.{$default}.database") !== ':memory:') {
            $this->markTestSkipped('Creates a probe table: only on phpunit\'s in-memory SQLite.');
        }
        $before = DB::connection($default);
        $before->statement('CREATE TABLE target_probe (id integer primary key)');
        $before->insert('INSERT INTO target_probe (id) VALUES (1)');

        // an empty source: the command stops after the target switch ("No WordPress tables …")
        config(['database.connections.empty_wp' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['commerce-import.source' => ['connection' => 'empty_wp', 'wp_path' => null, 'snapshots' => []]]);
        $this->artisan('commerce:import-wordpress', ['--target' => $default, '--detect' => true, '--no-wp-cli' => true])->assertFailed();

        $this->assertSame($before, DB::connection($default), 'the target connection was purged and re-created');
        $this->assertSame(1, (int) DB::connection($default)->table('target_probe')->count());
    }
}
