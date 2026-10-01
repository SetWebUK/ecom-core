<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Support\SqliteFunctions;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * A client project may run on SQLite (development, CI, a throw-away rehearsal of an import). The package's raw SQL
 * uses a few MySQL functions; every SQLite connection gets them from Support\SqliteFunctions, so the storefront
 * search suggestions and the back-office dashboard/reports work there too – with no test-only polyfills.
 * Only in-memory SQLite connections are opened here (never the shop's MySQL database).
 */
class SqliteSupportTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_probe');
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_every_new_sqlite_connection_gets_the_mysql_functions(): void
    {
        config(['database.connections.sqlite_probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $row = DB::connection('sqlite_probe')->selectOne(
            "select CHAR_LENGTH('héllo') as len, DATE_FORMAT('2026-09-28 07:05:09', '%Y-%m-%d %H:00:00') as bucket,
                CONCAT_WS('|', 'a', null, 'b') as joined, CONCAT(',', 'x', ',') as wrapped"
        );
        $this->assertSame(5, (int) $row->len);
        $this->assertSame('2026-09-28 07:00:00', $row->bucket);
        $this->assertSame('a|b', $row->joined);
        $this->assertSame(',x,', $row->wrapped);
    }

    public function test_date_format_specifiers(): void
    {
        $this->assertSame('2026-09-28 07:05:09', SqliteFunctions::dateFormat('2026-09-28 07:05:09', '%Y-%m-%d %H:%i:%s'));
        $this->assertSame('2026-40 Sep 100%', SqliteFunctions::dateFormat('2026-09-28', '%x-%v %b 100%%'));
        $this->assertNull(SqliteFunctions::dateFormat(null, '%Y'));
    }

    public function test_search_suggestions_and_admin_reports_work_on_sqlite(): void
    {
        $this->catalogue(); // category "Shirts" + a paid (processing) order

        $json = $this->get(route('search.suggest', ['q' => 'shirt']))->assertOk()->json();
        $this->assertSame(['Shirts'], array_column($json['categories'], 'name'));

        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.dashboard'))->assertOk();
        foreach (['today', '7d', '30d', 'year'] as $range) {
            $this->get(route('admin.reports.index', ['range' => $range]))->assertOk();
        }
    }
}
