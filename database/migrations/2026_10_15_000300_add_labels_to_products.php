<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product labels: short promotional flags such as "New Season" or "Special offer" (products.labels, a JSON list of
 * strings), set in the product editor or in bulk from the Products list, shown by themes on product cards and pages
 * (ProductPresenter::labels(), which adds the automatic ones from config commerce.catalog.labels). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'labels')) {
                $table->json('labels')->nullable()->after('is_featured');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('labels');
        });
    }
};
