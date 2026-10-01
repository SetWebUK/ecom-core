<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legacy product page rendered each "Technical specification" row as label + value + a short
 * explanatory line (e.g. "100% cotton" / "Soft, breathable and machine washable").
 * `key` identifies the row type (a client-defined slug such as make, material, size, sku, warranty, returns) so
 * cards/highlights can pick specific rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_specs', function (Blueprint $table) {
            if (! Schema::hasColumn('product_specs', 'key')) {
                $table->string('key', 50)->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('product_specs', 'description')) {
                $table->string('description')->nullable()->after('value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_specs', function (Blueprint $table) {
            $table->dropColumn(['key', 'description']);
        });
    }
};
