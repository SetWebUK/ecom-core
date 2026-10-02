<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WooCommerce REST API import (1.5). Additive only:
 *
 *  - `import_source` (nullable, indexed) on every table the importers key on a WordPress id (`wp_id`): null = the
 *    WordPress database importer and every row imported before 1.5; "woo:{host}" = rows of a WooCommerce REST API
 *    connection. Remote ids are only matched within their own source, so two shops' id 42 never collide.
 *  - `woo_api_imports`: one row per API import run (Admin › Import › WooCommerce API, commerce:import-woo-api) –
 *    options, live progress per entity, resume checkpoint, summary, who started it and when.
 */
return new class extends Migration
{
    public const TABLES = ['users', 'categories', 'attribute_values', 'products', 'product_variations', 'orders', 'pages',
        'posts', 'media', 'tax_rates', 'shipping_classes', 'shipping_zones'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'import_source')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('import_source', 100)->nullable()->index();
                });
            }
        }

        if (! Schema::hasTable('woo_api_imports')) {
            Schema::create('woo_api_imports', function (Blueprint $table) {
                $table->id();
                $table->string('status', 20)->index();           // pending | running | completed | failed | cancelled | interrupted
                $table->string('mode', 20)->default('rest');     // rest (wc/v3 with keys) | store (public Store API, catalogue only)
                $table->string('site_url');
                $table->string('source', 100)->nullable();       // import_source written on the rows (null = same site as the database import)
                $table->boolean('dry_run')->default(false);
                $table->json('options')->nullable();             // entities, images, existing, orders_after, since …
                $table->json('progress')->nullable();            // per entity: status, total, fetched, created, updated, skipped, failed
                $table->json('checkpoint')->nullable();          // resume point: entity + next page
                $table->json('summary')->nullable();
                $table->unsignedInteger('warnings')->default(0);
                $table->unsignedInteger('errors')->default(0);
                $table->string('via', 20)->nullable();           // admin | cli
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('user_email')->nullable();
                $table->unsignedInteger('pid')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_api_imports');
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'import_source')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropIndex(['import_source']);
                    $table->dropColumn('import_source');
                });
            }
        }
    }
};
