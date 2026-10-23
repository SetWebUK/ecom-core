<?php

namespace Pine\Commerce\Tests\Console;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Console\ScratchDropCommand;
use Pine\Commerce\Support\DatabaseClock;
use Pine\Commerce\Tests\Concerns\UsesApplicationDatabase;
use Pine\Commerce\Tests\TestCase;

/**
 * commerce:db:utc on real MySQL/MariaDB (zz_ scratch tables only): timestamps written in the server's clock read the
 * same in UTC afterwards, a time inside the clocks' skipped hour is then accepted, and a second run is refused.
 * Skipped when the MySQL server itself runs UTC (nothing to convert).
 */
class DatabaseUtcMysqlTest extends TestCase
{
    use UsesApplicationDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useScratchPrefix('zz_t_utc_');
        config(['database.connections.scratch.timezone' => null]); // the server's clock, as before DB_TIMEZONE
        DB::purge('scratch');
        ScratchDropCommand::drop('scratch');
        if (! DatabaseClock::problem('scratch')) {
            $this->markTestSkipped('The MySQL server runs UTC – nothing to convert.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) { // not booted when skipped (standalone)
            ScratchDropCommand::drop('scratch');
        }
        parent::tearDown();
    }

    public function test_timestamps_read_the_same_in_utc_and_a_second_run_is_refused(): void
    {
        $db = DB::connection('scratch');
        $db->getSchemaBuilder()->create('settings', function ($t) {
            $t->id();
            $t->string('group')->default('general');
            $t->string('key')->unique();
            $t->longText('value')->nullable();
            $t->timestamps();
        });
        $db->getSchemaBuilder()->create('events', function ($t) {
            $t->id();
            $t->timestamp('happened_at')->nullable();
            $t->timestamps();
        });
        $values = ['2025-01-15 09:30:00', '2025-07-15 09:30:00', '2025-10-26 01:30:00', null];
        foreach ($values as $v) {
            $db->table('events')->insert(['happened_at' => $v, 'created_at' => '2025-06-01 12:00:00', 'updated_at' => null]);
        }

        $this->artisan('commerce:db:utc', ['--connection' => 'scratch', '--dry-run' => true])->assertSuccessful();
        $this->assertSame($values, $db->table('events')->orderBy('id')->pluck('happened_at')->all(), 'dry run changes nothing');

        $this->artisan('commerce:db:utc', ['--connection' => 'scratch', '--force' => true])->assertSuccessful();
        $this->artisan('commerce:db:utc', ['--connection' => 'scratch', '--force' => true])
            ->expectsOutputToContain('Already converted')
            ->assertFailed();

        config(['database.connections.scratch.timezone' => '+00:00']);
        DB::purge('scratch');
        $db = DB::connection('scratch');
        $this->assertNull(DatabaseClock::problem('scratch'));
        $this->assertSame($values, $db->table('events')->orderBy('id')->pluck('happened_at')->all());
        $this->assertSame('2025-06-01 12:00:00', $db->table('events')->value('created_at'));

        // the value that stopped a WooCommerce import on a server set to UK time
        $db->table('events')->insert(['happened_at' => '2025-03-30 01:39:36']);
        $this->assertSame('2025-03-30 01:39:36', $db->table('events')->orderByDesc('id')->value('happened_at'));
        $this->artisan('commerce:db:utc', ['--connection' => 'scratch', '--force' => true])
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
    }
}
