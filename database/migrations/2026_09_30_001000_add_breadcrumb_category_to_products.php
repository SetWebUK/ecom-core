<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional breadcrumb category of a product, when it differs from the category in its URL (primary_category_id).
 *
 * WordPress could disagree with itself: WooCommerce/the permalink plugin put one category in the product URL while
 * the SEO plugin's breadcrumb (Rank Math: the primary term, else the first category by name) showed another. The
 * importer stores that breadcrumb category here so product breadcrumbs match the old site without changing any URL.
 * Null = the breadcrumb follows primary_category_id (every product created in the back office). No foreign key
 * (an additive, SQLite-friendly column); a category that no longer exists or is no longer assigned is ignored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'breadcrumb_category_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('breadcrumb_category_id')->nullable()->after('primary_category_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'breadcrumb_category_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('breadcrumb_category_id');
            });
        }
    }
};
