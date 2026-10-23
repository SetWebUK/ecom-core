<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled sales for variants: product_variations.sale_starts_at / sale_ends_at (UTC, nullable = no limit), honoured by
 * ProductVariation::isOnSale(). Before this a variant's sale price applied at once and for ever: the product's own sale
 * dates only covered simple products, and the WooCommerce importers dropped each variation's date_on_sale_from/to, so
 * a sale scheduled for next month on live went on sale as soon as it was imported. Additive; existing rows keep no dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            if (! Schema::hasColumn('product_variations', 'sale_starts_at')) {
                $table->timestamp('sale_starts_at')->nullable()->after('sale_price');
            }
            if (! Schema::hasColumn('product_variations', 'sale_ends_at')) {
                $table->timestamp('sale_ends_at')->nullable()->after('sale_starts_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            $table->dropColumn(['sale_starts_at', 'sale_ends_at']);
        });
    }
};
