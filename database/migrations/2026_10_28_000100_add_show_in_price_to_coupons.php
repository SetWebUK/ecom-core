<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic discounts shown in the shop price (Admin › Discounts › "Show the reduced price in the shop"): product
 * cards, product pages, the feed and the product schema show the price after the discount as a was/now price, the
 * way a sale price shows, while the basket goes on taking the discount off by its label.
 *  - coupons.show_in_price  only for automatic % off / amount off each item discounts without spend, email or
 *                           per-customer conditions (CouponRequest checks it; ProductPresenter::shopDiscounts())
 * Additive: existing discounts keep showing as an offer only (false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'show_in_price')) {
                $table->boolean('show_in_price')->default(false)->after('show_countdown');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('show_in_price');
        });
    }
};
