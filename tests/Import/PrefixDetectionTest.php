<?php

namespace Pine\Commerce\Tests\Import;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Source\PrefixDetector;
use Pine\Commerce\Import\Source\SourceConnection;
use Pine\Commerce\Tests\TestCase;

class PrefixDetectionTest extends TestCase
{
    public function test_candidates_need_options_posts_and_postmeta(): void
    {
        $tables = ['wp_options', 'wp_posts', 'wp_postmeta', '12_options', '12_posts', '12_postmeta', 'old_options', 'old_posts',
            'shop_settings_options', 'users', 'options'];

        $this->assertSame(['12_', 'wp_'], PrefixDetector::candidates($tables));
    }

    public function test_detect_confirms_the_prefix_with_a_siteurl_row(): void
    {
        WpFixture::boot('abc_');
        WpFixture::option('siteurl', 'https://shop.test');
        $db = DB::connection(WpFixture::CONNECTION);
        // a leftover install without siteurl must not count
        $db->statement('CREATE TABLE "zz_options" (option_id integer primary key, option_name varchar, option_value text)');
        $db->statement('CREATE TABLE "zz_posts" (ID integer primary key)');
        $db->statement('CREATE TABLE "zz_postmeta" (meta_id integer primary key)');

        $this->assertSame(['abc_', 'zz_'], PrefixDetector::candidates(PrefixDetector::tables($db)));
        $this->assertSame(['abc_'], PrefixDetector::detect($db));
    }

    public function test_source_connection_auto_detects_the_prefix(): void
    {
        WpFixture::boot('shop7_');
        WpFixture::option('siteurl', 'https://shop.test');
        config(['database.connections.fixture_base' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        // the fixture lives in its own in-memory PDO: point the base connection at it
        DB::purge('fixture_base');
        $pdo = DB::connection(WpFixture::CONNECTION)->getPdo();
        $source = new SourceConnection([], ['connection' => 'fixture_base']);
        app('db')->extend(SourceConnection::NAME, function ($config, $name) use ($pdo) {
            return new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', $config['prefix'] ?? '', $config);
        });

        $name = $source->register();

        $this->assertSame('shop7_', DB::connection($name)->getTablePrefix());
        $this->assertSame('auto-detected', $source->origin['prefix']);
        $this->assertSame('https://shop.test', DB::connection($name)->table('options')->where('option_name', 'siteurl')->value('option_value'));
    }
}
