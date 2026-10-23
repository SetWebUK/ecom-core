<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic discounts (Admin › Discounts › "Apply automatically"): a discount the basket applies by itself whenever
 * its rules match – the whole shop, chosen categories or products, dates, minimum spend – with no code to type.
 *  - coupons.auto_apply     applied automatically (the code is then only an internal reference)
 *  - coupons.label          the name customers see in the basket and on product pages, e.g. "Any 2 tees for £25"
 *  - coupons.buy_quantity   type "multibuy": how many eligible items make a set (amount = the price of the set)
 *  - coupons.show_countdown product pages count down to the end date
 * Additive: existing discount codes keep working exactly as before (auto_apply = false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'auto_apply')) {
                $table->boolean('auto_apply')->default(false)->index()->after('is_active');
            }
            if (! Schema::hasColumn('coupons', 'label')) {
                $table->string('label', 120)->nullable()->after('code');
            }
            if (! Schema::hasColumn('coupons', 'buy_quantity')) {
                $table->unsignedSmallInteger('buy_quantity')->nullable()->after('amount');
            }
            if (! Schema::hasColumn('coupons', 'show_countdown')) {
                $table->boolean('show_countdown')->default(false)->after('auto_apply');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['auto_apply']);
            $table->dropColumn(['auto_apply', 'label', 'buy_quantity', 'show_countdown']);
        });
    }
};
