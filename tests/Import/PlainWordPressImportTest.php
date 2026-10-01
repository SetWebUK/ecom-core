<?php

namespace Pine\Commerce\Tests\Import;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pine\Commerce\Tests\TestCase;

/**
 * A WordPress site WITHOUT WooCommerce (no plugin, none of its tables): commerce:import-wordpress must not crash on
 * missing tables. With no --only it skips the shop sections with a warning and imports content/users; asking for a
 * shop section explicitly is a clear error. Source = a temporary SQLite file, target = phpunit's in-memory SQLite.
 */
class PlainWordPressImportTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        // SAFETY: migrations run below only on an in-memory SQLite default connection – never on MySQL.
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:'
            || \Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Needs phpunit\'s in-memory SQLite target.');
        }
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->file = sys_get_temp_dir().'/commerce-plain-wp-'.bin2hex(random_bytes(4)).'.sqlite';
        touch($this->file);
        config(['database.connections.plain_wp' => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'foreign_key_constraints' => false]]);
        $this->buildSource();
        config([
            'commerce-import.source' => ['connection' => 'plain_wp', 'wp_path' => null, 'site_url' => null, 'site_host' => null, 'snapshots' => []],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('plain_wp');
        DB::purge('wordpress_import');
        if ($this->file !== '') {
            @unlink($this->file);
        }
        parent::tearDown();
    }

    private function buildSource(): void
    {
        $s = Schema::connection('plain_wp');
        $s->create('wp_options', function (Blueprint $t) {
            $t->increments('option_id');
            $t->string('option_name')->unique();
            $t->longText('option_value')->nullable();
            $t->string('autoload')->default('yes');
        });
        $s->create('wp_posts', function (Blueprint $t) {
            $t->bigIncrements('ID');
            $t->unsignedBigInteger('post_author')->default(0);
            $t->dateTime('post_date')->nullable();
            $t->dateTime('post_date_gmt')->nullable();
            $t->longText('post_content')->default('');
            $t->text('post_title')->default('');
            $t->text('post_excerpt')->default('');
            $t->string('post_status')->default('publish');
            $t->string('comment_status')->default('open');
            $t->string('post_password')->default('');
            $t->string('post_name')->default('');
            $t->dateTime('post_modified')->nullable();
            $t->dateTime('post_modified_gmt')->nullable();
            $t->unsignedBigInteger('post_parent')->default(0);
            $t->string('guid')->default('');
            $t->integer('menu_order')->default(0);
            $t->string('post_type')->default('post');
            $t->string('post_mime_type')->default('');
            $t->bigInteger('comment_count')->default(0);
        });
        foreach (['postmeta' => 'post_id', 'usermeta' => 'user_id', 'termmeta' => 'term_id', 'commentmeta' => 'comment_id'] as $table => $fk) {
            $s->create('wp_'.$table, function (Blueprint $t) use ($fk, $table) {
                $table === 'usermeta' ? $t->bigIncrements('umeta_id') : $t->bigIncrements('meta_id');
                $t->unsignedBigInteger($fk);
                $t->string('meta_key')->nullable();
                $t->longText('meta_value')->nullable();
            });
        }
        $s->create('wp_users', function (Blueprint $t) {
            $t->bigIncrements('ID');
            $t->string('user_login');
            $t->string('user_pass');
            $t->string('user_nicename')->default('');
            $t->string('user_email');
            $t->string('user_url')->default('');
            $t->dateTime('user_registered')->nullable();
            $t->integer('user_status')->default(0);
            $t->string('display_name')->default('');
        });
        $s->create('wp_terms', function (Blueprint $t) {
            $t->bigIncrements('term_id');
            $t->string('name');
            $t->string('slug');
            $t->bigInteger('term_group')->default(0);
        });
        $s->create('wp_term_taxonomy', function (Blueprint $t) {
            $t->bigIncrements('term_taxonomy_id');
            $t->unsignedBigInteger('term_id');
            $t->string('taxonomy');
            $t->longText('description')->default('');
            $t->unsignedBigInteger('parent')->default(0);
            $t->bigInteger('count')->default(0);
        });
        $s->create('wp_term_relationships', function (Blueprint $t) {
            $t->unsignedBigInteger('object_id');
            $t->unsignedBigInteger('term_taxonomy_id');
            $t->integer('term_order')->default(0);
        });
        $s->create('wp_comments', function (Blueprint $t) {
            $t->bigIncrements('comment_ID');
            $t->unsignedBigInteger('comment_post_ID');
            $t->string('comment_author')->default('');
            $t->string('comment_author_email')->default('');
            $t->dateTime('comment_date')->nullable();
            $t->dateTime('comment_date_gmt')->nullable();
            $t->text('comment_content')->default('');
            $t->string('comment_approved')->default('1');
            $t->string('comment_type')->default('comment');
            $t->unsignedBigInteger('comment_parent')->default(0);
            $t->unsignedBigInteger('user_id')->default(0);
        });

        $db = DB::connection('plain_wp');
        foreach ([
            'siteurl' => 'https://plain.example.test', 'home' => 'https://plain.example.test', 'blogname' => 'Plain Blog',
            'blogdescription' => 'Just a blog', 'permalink_structure' => '/%postname%/', 'active_plugins' => 'a:0:{}',
            'show_on_front' => 'posts', 'db_version' => '57155',
        ] as $name => $value) {
            $db->table('wp_options')->insert(['option_name' => $name, 'option_value' => $value]);
        }
        $db->table('wp_users')->insert(['ID' => 1, 'user_login' => 'editor', 'user_pass' => '$P$Bxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.', 'user_email' => 'editor@plain.example.test',
            'display_name' => 'Ed Itor', 'user_registered' => '2024-01-01 10:00:00']);
        $db->table('wp_usermeta')->insert(['user_id' => 1, 'meta_key' => 'wp_capabilities', 'meta_value' => 'a:1:{s:6:"editor";b:1;}']);
        $now = '2024-02-01 12:00:00';
        $db->table('wp_posts')->insert(['ID' => 10, 'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now, 'post_modified' => $now, 'post_modified_gmt' => $now,
            'post_title' => 'About us', 'post_name' => 'about-us', 'post_type' => 'page', 'post_content' => '<p>We write things.</p>']);
        $db->table('wp_posts')->insert(['ID' => 11, 'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now, 'post_modified' => $now, 'post_modified_gmt' => $now,
            'post_title' => 'Hello world', 'post_name' => 'hello-world', 'post_type' => 'post', 'post_content' => '<p>First post.</p>']);
        $db->table('wp_terms')->insert(['term_id' => 1, 'name' => 'News', 'slug' => 'news']);
        $db->table('wp_term_taxonomy')->insert(['term_taxonomy_id' => 1, 'term_id' => 1, 'taxonomy' => 'category', 'count' => 1]);
        $db->table('wp_term_relationships')->insert(['object_id' => 11, 'term_taxonomy_id' => 1]);
    }

    public function test_a_site_without_woocommerce_imports_content_and_skips_the_shop_sections(): void
    {
        $this->artisan('commerce:import-wordpress', ['--core-only' => true, '--no-wp-cli' => true, '--no-interaction' => true])
            ->expectsOutputToContain('WooCommerce is not active')
            ->assertSuccessful();

        $this->assertTrue(DB::table('pages')->where('path', 'about-us')->exists());
        $this->assertTrue(DB::table('posts')->where('slug', 'hello-world')->exists());
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_asking_for_a_shop_section_without_woocommerce_is_a_clear_error(): void
    {
        $this->artisan('commerce:import-wordpress', ['--core-only' => true, '--no-wp-cli' => true, '--only' => 'catalog', '--no-interaction' => true])
            ->expectsOutputToContain('WooCommerce is not active')
            ->assertFailed();
    }
}
