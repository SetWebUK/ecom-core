<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back office – Sales area:
 *  - users.admin_note: private staff notes on a customer (never shown to the customer)
 *  - indexes for the orders list filters (payment method) and the abandoned-checkouts list (carts.updated_at)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'admin_note')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('admin_note')->nullable()->after('marketing_opt_in');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->index('payment_method', 'orders_payment_method_index');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->index(['converted_at', 'updated_at'], 'carts_converted_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropIndex('carts_converted_updated_index');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_payment_method_index');
        });
        if (Schema::hasColumn('users', 'admin_note')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('admin_note');
            });
        }
    }
};
