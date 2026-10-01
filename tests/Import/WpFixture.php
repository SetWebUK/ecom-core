<?php

namespace Pine\Commerce\Tests\Import;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A WordPress/WooCommerce-shaped database in SQLite memory (connection 'wp_fixture', prefix 'wp_') for importer
 * tests: core tables, WooCommerce order items, HPOS tables. Nothing touches MySQL.
 */
final class WpFixture
{
    public const CONNECTION = 'wp_fixture';

    public static function boot(string $prefix = 'wp_'): \Illuminate\Database\Connection
    {
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => $prefix, 'foreign_key_constraints' => false]]);
        DB::purge(self::CONNECTION);
        $db = DB::connection(self::CONNECTION);
        $schema = Schema::connection(self::CONNECTION);

        $schema->create('options', function (Blueprint $t) {
            $t->increments('option_id');
            $t->string('option_name')->unique();
            $t->longText('option_value')->nullable();
            $t->string('autoload')->default('yes');
        });
        $schema->create('posts', function (Blueprint $t) {
            $t->bigIncrements('ID');
            $t->unsignedBigInteger('post_author')->default(0);
            $t->dateTime('post_date')->nullable();
            $t->dateTime('post_date_gmt')->nullable();
            $t->longText('post_content')->default('');
            $t->text('post_title')->default('');
            $t->text('post_excerpt')->default('');
            $t->string('post_status')->default('publish');
            $t->string('post_name')->default('');
            $t->dateTime('post_modified')->nullable();
            $t->dateTime('post_modified_gmt')->nullable();
            $t->unsignedBigInteger('post_parent')->default(0);
            $t->integer('menu_order')->default(0);
            $t->string('post_type')->default('post');
            $t->string('post_mime_type')->default('');
        });
        $schema->create('postmeta', function (Blueprint $t) {
            $t->bigIncrements('meta_id');
            $t->unsignedBigInteger('post_id');
            $t->string('meta_key')->nullable();
            $t->longText('meta_value')->nullable();
        });
        $schema->create('terms', function (Blueprint $t) {
            $t->bigIncrements('term_id');
            $t->string('name');
            $t->string('slug');
        });
        $schema->create('term_taxonomy', function (Blueprint $t) {
            $t->bigIncrements('term_taxonomy_id');
            $t->unsignedBigInteger('term_id');
            $t->string('taxonomy');
            $t->longText('description')->default('');
            $t->unsignedBigInteger('parent')->default(0);
            $t->bigInteger('count')->default(0);
        });
        $schema->create('term_relationships', function (Blueprint $t) {
            $t->unsignedBigInteger('object_id');
            $t->unsignedBigInteger('term_taxonomy_id');
            $t->integer('term_order')->default(0);
        });
        $schema->create('termmeta', function (Blueprint $t) {
            $t->bigIncrements('meta_id');
            $t->unsignedBigInteger('term_id');
            $t->string('meta_key')->nullable();
            $t->longText('meta_value')->nullable();
        });
        $schema->create('comments', function (Blueprint $t) {
            $t->bigIncrements('comment_ID');
            $t->unsignedBigInteger('comment_post_ID');
            $t->string('comment_author')->default('');
            $t->string('comment_author_email')->default('');
            $t->dateTime('comment_date_gmt')->nullable();
            $t->text('comment_content')->default('');
            $t->string('comment_approved')->default('1');
            $t->string('comment_type')->default('comment');
        });
        $schema->create('woocommerce_order_items', function (Blueprint $t) {
            $t->bigIncrements('order_item_id');
            $t->text('order_item_name');
            $t->string('order_item_type');
            $t->unsignedBigInteger('order_id');
        });
        $schema->create('woocommerce_order_itemmeta', function (Blueprint $t) {
            $t->bigIncrements('meta_id');
            $t->unsignedBigInteger('order_item_id');
            $t->string('meta_key')->nullable();
            $t->longText('meta_value')->nullable();
        });
        $schema->create('wc_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('status')->nullable();
            $t->string('currency')->nullable();
            $t->string('type')->nullable();
            $t->decimal('tax_amount', 26, 8)->nullable();
            $t->decimal('total_amount', 26, 8)->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('billing_email')->nullable();
            $t->dateTime('date_created_gmt')->nullable();
            $t->dateTime('date_updated_gmt')->nullable();
            $t->unsignedBigInteger('parent_order_id')->nullable();
            $t->string('payment_method')->nullable();
            $t->text('payment_method_title')->nullable();
            $t->string('transaction_id')->nullable();
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->text('customer_note')->nullable();
        });
        $schema->create('wc_order_addresses', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->string('address_type');
            foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $c) {
                $t->text($c)->nullable();
            }
        });
        $schema->create('wc_order_operational_data', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->string('created_via')->nullable();
            $t->string('woocommerce_version')->nullable();
            $t->boolean('prices_include_tax')->nullable();
            $t->string('order_key')->nullable();
            $t->dateTime('date_paid_gmt')->nullable();
            $t->dateTime('date_completed_gmt')->nullable();
            $t->decimal('shipping_tax_amount', 26, 8)->nullable();
            $t->decimal('shipping_total_amount', 26, 8)->nullable();
            $t->decimal('discount_tax_amount', 26, 8)->nullable();
            $t->decimal('discount_total_amount', 26, 8)->nullable();
        });
        $schema->create('wc_orders_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->string('meta_key')->nullable();
            $t->text('meta_value')->nullable();
        });

        return $db;
    }

    public static function option(string $name, $value): void
    {
        DB::connection(self::CONNECTION)->table('options')->updateOrInsert(['option_name' => $name],
            ['option_value' => is_array($value) ? serialize($value) : (string) $value]);
    }

    public static function term(int $id, string $taxonomy, string $name, string $slug, int $parent = 0): int
    {
        $db = DB::connection(self::CONNECTION);
        $db->table('terms')->insert(['term_id' => $id, 'name' => $name, 'slug' => $slug]);

        return $db->table('term_taxonomy')->insertGetId(['term_id' => $id, 'taxonomy' => $taxonomy, 'parent' => $parent]);
    }

    public static function post(array $row, array $meta = []): int
    {
        $db = DB::connection(self::CONNECTION);
        $id = $db->table('posts')->insertGetId($row + ['post_date_gmt' => '2025-01-01 10:00:00', 'post_modified_gmt' => '2025-01-02 10:00:00']);
        foreach ($meta as $k => $v) {
            $db->table('postmeta')->insert(['post_id' => $id, 'meta_key' => $k, 'meta_value' => is_array($v) ? serialize($v) : $v]);
        }

        return $id;
    }

    public static function relate(int $objectId, int $termTaxonomyId): void
    {
        DB::connection(self::CONNECTION)->table('term_relationships')->insert(['object_id' => $objectId, 'term_taxonomy_id' => $termTaxonomyId]);
    }
}
