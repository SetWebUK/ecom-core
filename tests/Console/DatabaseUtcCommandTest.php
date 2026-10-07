<?php

namespace Pine\Commerce\Tests\Console;

use Pine\Commerce\Support\DatabaseClock;
use Pine\Commerce\Support\Doctor;
use Pine\Commerce\Tests\TestCase;

/**
 * The database session time zone: the client skeleton sets it (DB_TIMEZONE=+00:00), commerce:db:utc and the doctor
 * leave SQLite alone. The MySQL conversion itself: DatabaseUtcMysqlTest (needs a client application's database).
 */
class DatabaseUtcCommandTest extends TestCase
{
    public function test_sqlite_has_no_session_time_zone_to_fix(): void
    {
        $this->assertNull(DatabaseClock::problem());
        $this->artisan('commerce:db:utc', ['--force' => true])
            ->expectsOutputToContain('Only MySQL/MariaDB')
            ->assertFailed();
        $titles = array_column(array_filter((new Doctor)->run(), fn ($r) => $r['group'] === 'Database'), 'title');
        $this->assertNotContains('Time zone', $titles);
    }

    public function test_new_clients_run_the_connection_in_utc(): void
    {
        $skeleton = dirname(__DIR__, 2).'/stubs/client-skeleton';
        $this->assertMatchesRegularExpression('/^DB_TIMEZONE=\+00:00$/m', (string) file_get_contents($skeleton.'/.env.example'));
        $config = (string) file_get_contents($skeleton.'/config/database.php');
        // mysql + scratch read it; an existing client without DB_TIMEZONE keeps its server clock (null) until converted
        $this->assertSame(2, substr_count($config, "'timezone' => env('DB_TIMEZONE'),"));
    }
}
